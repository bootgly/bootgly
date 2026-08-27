<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred;


use const Bootgly\WPI;
use function json_encode;
use function str_starts_with;
use Closure;
use LogicException;
use Throwable;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Recovering;


/**
 * Error-boundary fixture for the deferred-work specs.
 *
 * One class, several instances: the specs need to know WHICH boundary
 * answered and how many times each one was entered, far more than they need
 * distinct behaviours. `process()` is a real synchronous boundary, so the
 * specs also demonstrate the honest limit — its `sync:` body never appears
 * for a throw raised inside deferred work.
 */
final class Boundary implements Recovering
{
   // * Config
   /** Identity reported in every answer. */
   public readonly string $name;
   /** `answer` (JSON + status), `pass` (decline), `throw`, `handoff` (SSE), `nested` (child defer()) or `park` (wait() first). */
   public readonly string $mode;
   /** Answer on a fresh Response instead of the deferred clone. */
   public readonly bool $fresh;
   /** Recover only requests whose URI starts with this prefix (`''` = all). */
   public readonly string $prefix;

   // * Data
   /** Times `process()` ran — the synchronous admission. */
   public int $admissions = 0;
   /** Times `recover()` was consulted. */
   public int $recoveries = 0;
   /** @var array<int,string> Throwable classes seen by `recover()`, in order. */
   public array $seen = [];


   public function __construct (
      string $name,
      string $mode = 'answer',
      bool $fresh = false,
      string $prefix = ''
   )
   {
      $this->name = $name;
      $this->mode = $mode;
      $this->fresh = $fresh;
      $this->prefix = $prefix;
   }

   /**
    * @param Request $Request
    * @param Response $Response
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      $this->admissions++;
      // @
      try {
         return $next($Request, $Response);
      }
      catch (Throwable) {
         // : A synchronous boundary — never reached by a deferred throw
         return $Response(code: 500, body: "sync:{$this->name}");
      }
   }

   public function recover (Request $Request, Response $Response, Throwable $Throwable): null|Response
   {
      // ? Global-pipeline marker: stay inert for every other request
      if ($this->prefix !== '' && str_starts_with($Request->URI, $this->prefix) === false) {
         return null;
      }

      $this->recoveries++;
      $this->seen[] = $Throwable::class;

      // ?: Decline — the next boundary outward is offered the Throwable
      if ($this->mode === 'pass') {
         return null;
      }
      // ? A throwing boundary replaces the Throwable for the boundaries outward
      if ($this->mode === 'throw') {
         throw new LogicException("{$this->name}-failed");
      }
      // ?: Settle the generation from inside the boundary (SSE handoff)
      if ($this->mode === 'handoff') {
         $SSE = $Response->SSE;
         $SSE->open();
         $SSE->send(['recovered' => $this->name, 'throwable' => $Throwable::class]);
         $SSE->close();

         return $Response;
      }
      // ? Park before answering — on something that never becomes ready
      if ($this->mode === 'park') {
         Routes::park($Response, 10.0);
      }
      // ?: Hand the generation to a child deferral that answers later
      if ($this->mode === 'nested') {
         $name = $this->name;
         $class = $Throwable::class;
         $Response->defer(static function (Response $Child) use ($name, $class): void {
            $Child->wait();
            $Child->JSON->send(['recovered' => $name, 'throwable' => $class, 'nested' => true]);
         });

         return $Response;
      }

      // @ Answer
      $WPI = WPI;
      $timedOut = $Throwable instanceof Timeout;
      $code = $timedOut ? 503 : 500;
      $payload = [
         'recovered' => $this->name,
         'throwable' => $Throwable::class,
         'message' => $Throwable->getMessage(),
         'URI' => $Request->URI,
         'bound' => $WPI->Request === $Request,
         'timeout' => $timedOut ? $Throwable->timeout : null
      ];

      if ($this->fresh) {
         $Fresh = new Response(code: $code, body: (string) json_encode($payload));
         $Fresh->Header->set('Content-Type', 'application/json');

         return $Fresh;
      }

      $Response->JSON->send($payload);

      // :
      return $Response->code($code);
   }
}
