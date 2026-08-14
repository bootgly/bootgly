<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use const PHP_INT_MAX;
use function array_shift;
use function hrtime;
use function intdiv;
use WeakMap;

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Observability\Metrics\Gauge;
use Bootgly\ACI\Observability\Metrics\Histogram;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry\Admissions;


/**
 * HTTP request telemetry — records production exchanges through an isolated
 * admission phase and preserves the public Received/Handled event contract for
 * legacy/manual producers.
 *
 * Opt-in: nothing is recorded until `boot()` registers the observers. The HTTP
 * core opens a lifecycle token only for an observer, a public Received boundary
 * or the first clone/defer/SSE escape; ordinary synchronous requests keep the
 * allocation-free path when telemetry is off.
 *
 * Hot-path design: count / in-flight / status are plain scalar accumulators incremented per request
 * (no instrument method calls); they are exposed through *observable* instruments that read those
 * scalars only at scrape time. Only the duration histogram is recorded directly (per-observation
 * bucketing has no scalar form). A per-exchange terminal token correlates each monotonic start with
 * its own synchronous, deferred or cancelled completion even when requests finish out of order.
 */
class Telemetry
{
   // * Data
   private Histogram $Duration;
   /** @var WeakMap<Exchange,true> */
   private WeakMap $Exchanges;
   /** @var array<int,Exchange> */
   private array $Legacy = [];

   // * Metadata
   private int $count = 0;
   private int $inFlight = 0;
   /** @var array<int, int> Per status class (1..5). */
   private array $status = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];


   /**
    * Create + register the HTTP request instruments on the given registry.
    *
    * @param Observability $Observability The registry the instruments are pushed onto.
    */
   public function __construct (Observability $Observability)
   {
      // * Data — duration is recorded directly (histogram bucketing has no scalar accumulator form)
      $this->Duration = new Histogram(name: 'http_request_duration_seconds', help: 'HTTP request duration in seconds.');
      $this->Exchanges = new WeakMap;

      // @ Observable instruments — read the hot-path scalars at scrape time, not per request
      $Observability->Metrics
         ->push($this->Duration)
         ->push(new Counter(
            name: 'http_requests_total', help: 'Total HTTP requests handled.',
            observe: fn (): int => $this->count
         ))
         ->push(new Gauge(
            name: 'http_requests_in_flight', help: 'HTTP requests currently in flight.',
            observe: fn (): int => $this->inFlight
         ));

      // # Per status class (1xx..5xx) — fixed observable series under one metric name
      foreach ([1, 2, 3, 4, 5] as $class) {
         $Observability->Metrics->push(new Counter(
            name: 'http_responses_total',
            help: 'Total HTTP responses by status class.',
            labels: ['class' => "{$class}xx"],
            observe: fn (): int => $this->status[$class]
         ));
      }
   }

   /**
    * Register the request-lifecycle listeners that record the metrics.
    *
    * @return void
    */
   public function boot (): void
   {
      $Emitter = Emitter::$Instance;

      $Observer = function (Exchange $Exchange): void {
         $this->record($Exchange);
      };
      Admissions::listen($Emitter, $Observer);

      // @ Received — bind accounting directly to THIS exchange's idempotent
      //   terminal owner. The closure-carried timestamp cannot be overwritten
      //   when a synchronous request completes while a deferred one is parked.
      // ! Core admission must precede ordinary stoppable/throwing listeners;
      //   otherwise their propagation decision could make an admitted request
      //   invisible to Telemetry before the encoder terminalizes its token.
      $Emitter->listen(RequestEvents::Received, function (Emission $Emission) use (
         $Observer,
      ): void {
         $Request = $Emission->payload[0] ?? null;
         $Exchange = $Emission->payload[1] ?? null;
         if ($Exchange instanceof Exchange === false && $Request instanceof Request) {
            $Exchange = Exchange::fetch($Request);
         }

         if ($Exchange instanceof Exchange === false) {
            // @ Compatibility for legacy/manual producers that emit the
            //   documented Received -> Handled pair without an internal token.
            $Exchange = new Exchange;
            $this->Legacy[] = $Exchange;
         }

         // @ Production invokes the same observer through Admissions before
         //   the stoppable public bus; record() deduplicates this delivery.
         $Observer($Exchange);
      }, priority: PHP_INT_MAX);

      $Emitter->listen(RequestEvents::Handled, function (Emission $Emission): void {
         $Request = $Emission->payload[0] ?? null;
         $Response = $Emission->payload[1] ?? null;
         if (
            ($Request instanceof Request && Exchange::fetch($Request) !== null)
            || ($Response instanceof Response && Exchange::fetch($Response) !== null)
         ) {
            // ! Production completion belongs to its admitted Exchange and
            //   must not consume a pending manual compatibility pair.
            return;
         }

         $Exchange = array_shift($this->Legacy);
         if ($Exchange === null) {
            return;
         }

         $Exchange->finish($Response instanceof Response ? $Response : null);
      }, priority: PHP_INT_MAX);
   }

   /**
    * Record one exchange once for this Telemetry registry.
    */
   private function record (Exchange $Exchange): void
   {
      if (isset($this->Exchanges[$Exchange])) {
         return;
      }
      $this->Exchanges[$Exchange] = true;

      $started = hrtime(true);
      // ! Increment before observe(): the exchange may already be terminal.
      //   Late replay then decrements synchronously without going negative.
      $this->inFlight++;
      $Exchange->observe(function (
         Exchange $Exchange,
         null|int $code,
      ) use ($started): void {
         $this->Duration->observe((hrtime(true) - $started) / 1_000_000_000);
         $this->count++;
         $this->inFlight--;

         // # Status class (2xx, 4xx, …). A transport/scheduler
         //   cancellation closes core accounting without inventing 499.
         if ($code !== null) {
            $class = intdiv($code, 100);
            if (isSet($this->status[$class])) {
               $this->status[$class]++;
            }
         }
      });
   }
}
