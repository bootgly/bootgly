<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Schedule;


use function is_callable;
use Closure;


/**
 * A single scheduled job: an identity, a task, a cadence and its policies.
 *
 * Pure configuration holder built fluently. The engine (`Schedule`) reads this
 * config to decide when and how to dispatch — locking, state and events live
 * there, never here.
 */
class Job
{
   // * Config
   /**
    * Stable identity (used for the lock file and last-run state key).
    */
   public private(set) string $id;
   /**
    * The task to execute: a Closure or an invokable class-string.
    *
    * @var Closure|class-string
    */
   public private(set) Closure|string $Task;

   // * Data
   /**
    * The cadence. Unset until repeat() is called.
    */
   public private(set) Cron $Cron;
   /**
    * Missed-run catch-up policy.
    */
   public private(set) Catchups $Catchup = Catchups::Skip;
   /**
    * Whether overlap prevention (per-job file lock) is enabled.
    */
   public private(set) bool $locked = false;


   /**
    * @param Closure|class-string $Task
    */
   public function __construct (string $id, Closure|string $Task)
   {
      // * Config
      $this->id = $id;
      $this->Task = $Task;
   }

   /**
    * Set the cadence from a named Frequencies cadence, a raw cron string,
    * or an explicit Cron instance.
    */
   public function repeat (Frequencies|string|Cron $frequency, null|string $at = null): static
   {
      $this->Cron = match (true) {
         $frequency instanceof Cron        => $frequency,
         $frequency instanceof Frequencies => new Cron($frequency->resolve($at)),
         default                           => new Cron($frequency),
      };

      // :
      return $this;
   }

   /**
    * Enable overlap prevention (the engine acquires a per-job file lock).
    */
   public function lock (): static
   {
      $this->locked = true;

      // :
      return $this;
   }

   /**
    * Set the missed-run catch-up policy.
    */
   public function recover (Catchups $policy): static
   {
      $this->Catchup = $policy;

      // :
      return $this;
   }

   /**
    * Execute the task.
    */
   public function run (): void
   {
      $Task = $this->Task;

      // ? Closure task
      if ($Task instanceof Closure) {
         $Task();
         return;
      }

      // @ Invokable class-string
      $Object = new $Task;

      if (is_callable($Object)) {
         $Object();
      }
   }
}
