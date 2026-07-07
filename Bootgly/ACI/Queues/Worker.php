<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues;


use function is_subclass_of;
use function microtime;
use RuntimeException;
use Throwable;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Queues\Config;
use Bootgly\ACI\Queues\Events;
use Bootgly\ACI\Queues\Handler;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\Queue;


/**
 * Queue consume loop.
 *
 * Reusable by any consumer (the `worker run` command and future adapters):
 * tick() processes at most one due job. A handler failure never escapes — it is
 * caught and turned into a retry (release + backoff) or a dead-letter (bury) once
 * attempts are exhausted, surfacing as `Processed` / `Failed` events.
 */
class Worker
{
   // * Config
   public Config $Config;

   // * Metadata
   private Queue $Queue;


   /**
    * Create a worker that drains the given queue using its configuration.
    *
    * @param Queue $Queue Queue to drain.
    * @param Config $Config Queue configuration.
    */
   public function __construct (Queue $Queue, Config $Config)
   {
      // * Config
      $this->Config = $Config;

      // * Metadata
      $this->Queue = $Queue;
   }

   /**
    * Process at most one due job; true when one was handled, false when idle.
    */
   public function tick (): bool
   {
      $Job = $this->Queue->reserve();
      // ?
      if ($Job === null) {
         return false;
      }

      // @
      $this->process($Job);

      // :
      return true;
   }

   /**
    * Run a job's handler under a Throwable guard, then ack / retry / bury.
    */
   private function process (Job $Job): void
   {
      $Emitter = Emitter::$Instance;
      $started = microtime(true);

      try {
         $class = $Job->Handler;

         // ? Refuse to instantiate anything that is not a declared Handler — defence in
         //   depth against a tampered store driving `new $class` (covers non-existent and
         //   non-Handler classes alike). PHPStan trusts the class-string<Handler> type;
         //   the persisted store is not trustworthy.
         // @phpstan-ignore function.alreadyNarrowedType, identical.alwaysFalse
         if (is_subclass_of($class, Handler::class) === false) {
            throw new RuntimeException("Invalid job handler: {$class}");
         }

         $Handler = new $class();
         $Handler->handle($Job);

         $this->Queue->complete($Job);

         $duration = (microtime(true) - $started) * 1000;
         $Emitter->check(Events::Processed) && $Emitter->emit(Events::Processed, $Job, $duration);
      }
      catch (Throwable $Throwable) {
         // ? Exhausted attempts → dead-letter; otherwise requeue with backoff
         $failures = $Job->attempts + 1;

         if ($failures >= $this->Config->attempts) {
            $this->Queue->bury($Job);
            $Emitter->check(Events::Failed) && $Emitter->emit(Events::Failed, $Job, $Throwable, false);
         }
         else {
            $delay = $this->Config->backoff->delay($failures, $this->Config->base);
            $this->Queue->release($Job, $delay);
            $Emitter->check(Events::Failed) && $Emitter->emit(Events::Failed, $Job, $Throwable, true);
         }
      }
   }
}
