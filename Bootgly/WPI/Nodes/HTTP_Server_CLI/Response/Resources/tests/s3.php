<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\S3;


use function hrtime;
use function is_resource;
use function microtime;
use function spl_object_id;
use function stream_select;
use Closure;
use Fiber;
use ReflectionProperty;
use stdClass;
use Throwable;

use Bootgly\ACI\Events\Readiness;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\S3;


require_once __DIR__ . '/../../../../../../ABI/Resources/Storage/tests/disk.php';
require_once __DIR__ . '/../../../../../../ABI/Resources/Storage/tests/fixtures/S3.php';


/**
 * The S3 resource rig (test helper): a host reactor standing in for the worker's — read from
 * `TCP_Server_CLI::$Event`, as a worker does — and a wait bridge that runs it inline until the
 * parked client is woken or 120 ms passed (past the client's non-suspending-wait tripwire).
 *
 * `$State->trap` (a `Closure(): null|Throwable`) is asked before each wait: a Throwable it returns
 * is thrown from the wait, as the server's `Fiber::throw()` of a deferral `Timeout` surfaces from
 * the parked `Fiber::suspend()`. `$State->park` is the worker's own bridge instead: it suspends,
 * and `spin()` resumes the parked Fiber when its readiness fires or its deadline passes.
 * `$State->waits` counts the waits; `$State->restore()` puts the previous reactor back.
 */
function mount (): stdClass
{
   $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
   $Event = $Host->Event;
   $Previous = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
   TCP_Server_CLI::$Event = $Event;

   $State = new stdClass;
   $State->Host = $Host;
   $State->Event = $Event;
   $State->trap = null;
   $State->waits = 0;
   $State->pump = static function (mixed $Readiness = null) use ($Event, $State): mixed {
      $State->waits++;
      $trap = $State->trap;
      if ($trap instanceof Closure) {
         $Throwable = $trap();
         if ($Throwable instanceof Throwable) {
            throw $Throwable;
         }
      }

      $start = hrtime(true);
      // @@ One short reactor slice at a time, until woken or 120 ms passed
      do {
         $Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
         $Event->defer(microtime(true) + 0.01, static function () use ($Event): void {
            $Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
         });
         $Event->loop();

         $woken = false;
         if ($Readiness instanceof Readiness && is_resource($Readiness->socket)) {
            $read = [$Readiness->socket];
            $write = null;
            $except = null;
            $woken = @stream_select($read, $write, $except, 0) > 0;
         }
      } while ($woken === false && hrtime(true) - $start < 120_000_000);

      return null;
   };
   // ! The suspending bridge: the parked Fiber leaves its readiness and suspends, as in a worker
   $State->Parked = [];
   $State->park = static function (mixed $Readiness = null) use ($State): mixed {
      $State->waits++;
      $State->Parked[spl_object_id((object) Fiber::getCurrent())] = $Readiness;

      return Fiber::suspend();
   };
   $State->restore = static function () use ($Previous): void {
      if ($Previous !== null) {
         TCP_Server_CLI::$Event = $Previous;
      }
   };

   // :
   return $State;
}

/**
 * Run `$Work` as one deferred job — a Fiber with a live generation token — and settle the token
 * when it returns, as a finished job does. Returns what the work returned, or what it threw.
 */
function run (Closure $Work): mixed
{
   $result = null;
   $Fiber = new Fiber(static function () use ($Work, &$result): void {
      try {
         $result = $Work();
      }
      catch (Throwable $Throwable) {
         $result = $Throwable;
      }
   });
   $Token = Cancellation::open($Fiber);
   $Fiber->start();
   $Token->finish();

   // :
   return $result;
}

/**
 * Start `$Work` as one deferred job that parks on `$State->park`: a Fiber with a live generation
 * token, started at once. The job (`Fiber`, `Token`, `result`) is driven by `spin()`.
 */
function launch (Closure $Work): stdClass
{
   $Job = new stdClass;
   $Job->result = null;
   $Job->Fiber = new Fiber(static function () use ($Work, $Job): void {
      try {
         $Job->result = $Work();
      }
      catch (Throwable $Throwable) {
         $Job->result = $Throwable;
      }
   });
   $Job->Token = Cancellation::open($Job->Fiber);
   $Job->Fiber->start();

   // :
   return $Job;
}

/**
 * Drive the reactor and the parked jobs, as a worker does, until every job ended or `$seconds`
 * passed: a parked job resumes when its readiness fires or its deadline passes, and a job that
 * ended has its generation settled (`finish()`). `$Tick(float $elapsed)` runs once per slice: it
 * may throw into a parked job (`$Job->Fiber->throw(...)`) or drop one, as the server does.
 *
 * @param array<int,stdClass> $Jobs
 */
function spin (stdClass $State, array $Jobs, float $seconds, null|Closure $Tick = null): void
{
   $Event = $State->Event;
   $start = microtime(true);

   // @@ One reactor slice at a time
   while (microtime(true) - $start < $seconds) {
      $Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
      $Event->defer(microtime(true) + 0.005, static function () use ($Event): void {
         $Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
      });
      $Event->loop();

      if ($Tick !== null) {
         $Tick(microtime(true) - $start);
      }

      $running = 0;
      foreach ($Jobs as $Job) {
         $Fiber = $Job->Fiber;
         if ($Fiber === null) {
            continue;
         }
         if ($Fiber->isTerminated() === true) {
            if ($Job->Token->check() === false) {
               $Job->Token->finish();
            }

            continue;
         }
         $running++;
         if ($Fiber->isSuspended() === false) {
            continue;
         }

         $Readiness = $State->Parked[spl_object_id($Fiber)] ?? null;
         $due = true;
         if ($Readiness instanceof Readiness && is_resource($Readiness->socket)) {
            $read = [$Readiness->socket];
            $write = null;
            $except = null;
            $due = @stream_select($read, $write, $except, 0) > 0
               || ($Readiness->deadline > 0 && microtime(true) >= $Readiness->deadline);
         }
         if ($due === true) {
            $Fiber->resume();
         }
      }
      if ($running === 0) {
         break;
      }
   }

   // @ The jobs that ended in the last slice settle too
   foreach ($Jobs as $Job) {
      if ($Job->Fiber !== null && $Job->Fiber->isTerminated() === true && $Job->Token->check() === false) {
         $Job->Token->finish();
      }
   }
}

/**
 * The budget bytes every S3 resource of this process holds (the worker's counter).
 */
function measure (): int
{
   $Property = new ReflectionProperty(S3::class, 'inflight');

   // :
   return (int) $Property->getValue();
}
