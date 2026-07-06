<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use function microtime;
use Closure;
use Throwable;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Schedule\Catchups;
use Bootgly\ACI\Schedule\Events;
use Bootgly\ACI\Schedule\Job;
use Bootgly\ACI\Schedule\Lock;
use Bootgly\ACI\Schedule\State;


/**
 * Cron-style job scheduler — registry and dispatch engine.
 *
 * Jobs are declared fluently via add(); a worker calls tick() once per minute
 * to dispatch every job whose cadence matches. Locking, last-run state and
 * lifecycle events are applied here (the parent), never in Job.
 *
 * Distinct from the I/O Fiber scheduler `ACI/Events/Scheduler`.
 */
class Schedule
{
   // * Data
   /**
    * Registered jobs.
    *
    * @var array<int,Job>
    */
   public private(set) array $Jobs = [];

   // * Metadata
   /**
    * Last-run persistence (catch-up policy + bookkeeping).
    */
   private State $State;


   public function __construct ()
   {
      // !
      $this->State = new State();
   }

   /**
    * Register a job and return it for fluent configuration.
    *
    * @param Closure|class-string $Task
    */
   public function add (string $id, Closure|string $Task): Job
   {
      $Job = new Job($id, $Task);

      $this->Jobs[] = $Job;

      // :
      return $Job;
   }

   /**
    * Dispatch every job whose cadence matches the given minute.
    */
   public function tick (int $timestamp): void
   {
      foreach ($this->Jobs as $Job) {
         // ? Skip jobs that never had a cadence declared
         if (isSet($Job->Cron) === false) {
            continue;
         }

         if ($Job->Cron->check($timestamp)) {
            $this->dispatch($Job, $timestamp);
         }
      }
   }

   /**
    * Catch-up pass for missed runs, run once on worker boot.
    *
    * For each job, compare the last-run timestamp with the next due time: when
    * a run was missed, `Catchups::Once` dispatches once now, while
    * `Catchups::Skip` simply advances the baseline. Jobs that never ran get a
    * baseline so history does not trigger a flood of catch-ups.
    */
   public function recover (int $now): void
   {
      foreach ($this->Jobs as $Job) {
         // ? Skip jobs that never had a cadence declared
         if (isSet($Job->Cron) === false) {
            continue;
         }

         $last = $this->State->fetch($Job->id);

         // ? Never ran: set baseline, no catch-up
         if ($last === 0) {
            $this->State->update($Job->id, $now);
            continue;
         }

         // ? Was a scheduled run missed since $last?
         $missed = $Job->Cron->advance($last) <= $now;

         if ($missed === false) {
            continue;
         }

         // ? Once: run now; Skip: drop missed runs and advance the baseline
         if ($Job->Catchup === Catchups::Once) {
            $this->dispatch($Job, $now); // updates state to $now
         }
         else {
            $this->State->update($Job->id, $now);

            $Emitter = Emitter::$Instance;
            $Emitter->check(Events::Skipped) && $Emitter->emit(Events::Skipped, $Job->id, 'catchup-skip');
         }
      }
   }

   /**
    * Run a single due job: lock (if enabled), execute, record, release.
    *
    * The task runs inside a Throwable guard so one job's failure can never kill
    * the worker; failures surface as a `Failed` event instead.
    */
   private function dispatch (Job $Job, int $now): void
   {
      $Emitter = Emitter::$Instance;

      // ? Overlap prevention: bail out when another holder owns the lock
      $Lock = null;
      if ($Job->locked) {
         $Lock = new Lock($Job->id);

         if ($Lock->acquire() === false) {
            $Emitter->check(Events::Skipped) && $Emitter->emit(Events::Skipped, $Job->id, 'overlap');
            return;
         }
      }

      // @
      $Emitter->check(Events::Started) && $Emitter->emit(Events::Started, $Job->id, $Job);

      $started = microtime(true);

      try {
         $Job->run();

         $duration = (microtime(true) - $started) * 1000;
         $Emitter->check(Events::Finished) && $Emitter->emit(Events::Finished, $Job->id, $duration);
      }
      catch (Throwable $Throwable) {
         $Emitter->check(Events::Failed) && $Emitter->emit(Events::Failed, $Job->id, $Throwable);
      }
      finally {
         $this->State->update($Job->id, $now);

         $Lock?->release();
      }
   }
}
