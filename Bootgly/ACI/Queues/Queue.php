<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues;


use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Queues\Driver;
use Bootgly\ACI\Queues\Events;
use Bootgly\ACI\Queues\Job;


/**
 * A single named queue bound to a driver.
 *
 * Thin scope over the driver: every operation forwards the queue name. push()
 * emits `Dispatch`; the Worker owns the `Processed`/`Failed` events.
 */
class Queue
{
   // * Config
   public private(set) string $name;

   // * Metadata
   private Driver $Driver;


   /**
    * Bind the queue to its name and driver.
    *
    * @param string $name Queue name.
    * @param Driver $Driver Backing driver.
    */
   public function __construct (string $name, Driver $Driver)
   {
      // * Config
      $this->name = $name;

      // * Metadata
      $this->Driver = $Driver;
   }

   /**
    * Enqueue a job (emits `Dispatch`).
    *
    * @param Job $Job Job to enqueue.
    */
   public function enqueue (Job $Job): bool
   {
      $enqueued = $this->Driver->enqueue($this->name, $Job);

      // @ Event — guarded so a no-listener enqueue stays zero-allocation
      $Emitter = Emitter::$Instance;
      $Emitter->check(Events::Dispatch) && $Emitter->emit(Events::Dispatch, $this->name, $Job);

      // :
      return $enqueued;
   }

   /**
    * Claim the next due job, or null when the queue is idle.
    */
   public function reserve (): null|Job
   {
      // :
      return $this->Driver->reserve($this->name);
   }

   /**
    * Acknowledge a finished job.
    *
    * @param Job $Job Reserved job to acknowledge.
    */
   public function complete (Job $Job): bool
   {
      // :
      return $this->Driver->complete($this->name, $Job);
   }

   /**
    * Requeue a job to run again after $delay seconds.
    *
    * @param Job $Job Reserved job to requeue.
    * @param int $delay Seconds until the job becomes due again.
    */
   public function release (Job $Job, int $delay = 0): bool
   {
      // :
      return $this->Driver->release($this->name, $Job, $delay);
   }

   /**
    * Dead-letter a job.
    *
    * @param Job $Job Reserved job to dead-letter.
    */
   public function bury (Job $Job): bool
   {
      // :
      return $this->Driver->bury($this->name, $Job);
   }

   /**
    * Release stale reserved claims; returns the count recovered.
    */
   public function recover (): int
   {
      // :
      return $this->Driver->recover($this->name);
   }

   /**
    * Number of ready jobs.
    */
   public function count (): int
   {
      // :
      return $this->Driver->count($this->name);
   }

   /**
    * Remove every job for this queue.
    */
   public function clear (): bool
   {
      // :
      return $this->Driver->clear($this->name);
   }
}
