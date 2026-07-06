<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use function hrtime;
use function intdiv;

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Observability\Metrics\Gauge;
use Bootgly\ACI\Observability\Metrics\Histogram;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;


/**
 * HTTP request telemetry — records per-request metrics into an Observability registry by listening
 * to the existing `Request\Events::Received`/`Handled` lifecycle events.
 *
 * Opt-in: nothing is recorded until `boot()` registers the listeners, so the server hot path stays
 * zero-cost when telemetry is off (the emit sites in the Encoder are `isSet`-guarded).
 *
 * Hot-path design: count / in-flight / status are plain scalar accumulators incremented per request
 * (no instrument method calls); they are exposed through *observable* instruments that read those
 * scalars only at scrape time. Only the duration histogram is recorded directly (per-observation
 * bucketing has no scalar form). Duration uses a monotonic start paired across Received→Handled,
 * which is exact for synchronous responses (the deferred/async path does not emit `Handled`).
 */
class Telemetry
{
   // * Data
   private Histogram $Duration;

   // * Metadata
   private int $count = 0;
   private int $inFlight = 0;
   /** @var array<int, int> Per status class (1..5). */
   private array $status = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
   private int $started = 0;


   /**
    * Create + register the HTTP request instruments on the given registry.
    *
    * @param Observability $Observability The registry the instruments are pushed onto.
    */
   public function __construct (Observability $Observability)
   {
      // * Data — duration is recorded directly (histogram bucketing has no scalar accumulator form)
      $this->Duration = new Histogram(name: 'http_request_duration_seconds', help: 'HTTP request duration in seconds.');

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

      // @ Received — a request is about to be processed: count it in-flight + stamp a monotonic start
      $Emitter->listen(RequestEvents::Received, function (): void {
         $this->inFlight++;
         $this->started = hrtime(true);
      });

      // @ Handled — response ready (sync path): record duration + total + status class; release in-flight
      $Emitter->listen(RequestEvents::Handled, function (Emission $Emission): void {
         if ($this->started !== 0) {
            $this->Duration->observe((hrtime(true) - $this->started) / 1_000_000_000);
            $this->started = 0;
         }

         $this->count++;
         $this->inFlight--;

         // # Status class (2xx, 4xx, …)
         $Response = $Emission->payload[1] ?? null;
         if ($Response instanceof Response) {
            $class = intdiv($Response->code, 100);
            if (isSet($this->status[$class])) {
               $this->status[$class]++;
            }
         }
      });
   }
}
