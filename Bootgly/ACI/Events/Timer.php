<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * Inspired by Workerman\Timer
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events;


use const PHP_INT_MAX;
use const SIGALRM;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function call_user_func_array;
use function function_exists;
use function gc_collect_cycles;
use function is_array;
use function pcntl_alarm;
use function pcntl_signal;
use function time;
use SplQueue;
use Throwable;

use Bootgly\ACI\Events\Timer\Reset as TimerReset;


class Timer
{
   // * Config
   // ...

   // * Data
   /**
    * @var array<int,array<int,array{0:int,1:callable,2:array<mixed>,3:bool}>>
    */
   protected static array $tasks = [];
   /** @var array<int,bool> */
   protected static array $status = [];

   // * Metadata
   protected static int $id = 0;
   /** @var SplQueue<array<mixed>> Detached callback graphs awaiting release. */
   private static SplQueue $ReleaseQueue;
   /** Number of process-local deletion drains currently executing. */
   private static int $deletionDepth = 0;
   /** At least one coalesced full-wheel reset needs owner notification. */
   private static bool $resetPending = false;
   /** True only while the outer deletion drain notifies reset owners. */
   private static bool $resetNotifying = false;
   /** Maximum detached callback generations released by one outer touch. */
   private const int RELEASE_BUDGET = 256;


   /**
    * Initialize the timer.
    *
    * @param callable $handler The signal handler for SIGALRM.
    *
    * @return bool Returns true on success, false on failure.
    */
   public static function init (callable $handler): bool
   {
      if (function_exists('pcntl_signal')) {
         return pcntl_signal(SIGALRM, $handler, false);
      }

      return false;
   }

   /**
    * Add a timer.
    *
    * @param int $interval
    * @param callable $handler
    * @param array<mixed> $args
    * @param bool $persistent
    * @return int|false
    */
   public static function add (
      int $interval, callable $handler, array $args = [], bool $persistent = true
   ): int|false
   {
      if ($interval <= 0) {
         return false;
      }
      self::drain();
      if ( empty(self::$tasks) ) {
         pcntl_alarm(1);
      }

      $runtime = time() + $interval;

      if ( ! isSet(self::$tasks[$runtime]) ) {
         self::$tasks[$runtime] = [];
      }

      self::$id = (self::$id === PHP_INT_MAX) ? 1 : ++self::$id;

      self::$status[self::$id] = true;
      self::$tasks[$runtime][self::$id] = [
         $interval, $handler, $args, $persistent
      ];

      return self::$id;
   }

   /**
    * Tick the timer, executing due tasks.
    *
    * @return void
    */
   public static function tick (): void
   {
      self::drain();
      if ( empty(self::$tasks) ) {
         pcntl_alarm(0);

         return;
      }

      pcntl_alarm(1);

      foreach (self::$tasks as $runtime => $tasks) {
         if (time() >= $runtime) {
            foreach ($tasks as $index => $task) {
               $interval   = $task[0];
               $handler    = $task[1];
               $args       = $task[2];
               $persistent = $task[3];

               // @ Detach the executed task from the LIVE bucket instead of
               //   dropping the whole bucket below: `$tasks` is a by-value
               //   snapshot, while a persistent task re-arms into the live map
               //   — possibly into a bucket still AHEAD in this same snapshot,
               //   which a blocking handler can make due before it is reached.
               //   Dropping that bucket wholesale destroys the re-armed task
               //   (its `$status` entry stays `true`, so it never fires again
               //   and is never re-added). Same idiom as `del()` below.
               unset(self::$tasks[$runtime][$index]);

               try {
                  call_user_func_array($handler, $args);
               }
               catch (Throwable) {
                  // ...
               }

               if ($persistent && ! empty(self::$status[$index])) {
                  $_runtime_ = time() + $interval;

                  if ( ! isSet(self::$tasks[$_runtime_]) ) {
                     self::$tasks[$_runtime_] = [];
                  }

                  self::$tasks[$_runtime_][$index] = [
                     $interval, $handler, $args, $persistent
                  ];
               }
               else if ($persistent === false) {
                  unset(self::$status[$index]);
               }
            }

            // ? The bucket is empty only when nothing re-armed into it — a
            //   handler may also have cleared the whole map (`Timer::del()`).
            if ( isSet(self::$tasks[$runtime]) && self::$tasks[$runtime] === [] ) {
               unset(self::$tasks[$runtime]);
            }
         }
      }
   }

   /**
    * Delete one timer, or reset the complete timer wheel when id is zero.
    *
    * @param int $id Timer identifier returned by add(), or zero for all timers.
    *
    * @return bool
    */
   public static function del (int $id = 0): bool
   {
      // @ Delete all tasks
      if ($id === 0) {
         $Detached = self::$tasks;
         self::$tasks = [];
         self::$status = [];
         self::$resetPending = true;

         pcntl_alarm(0);
         self::defer($Detached);
         if (self::$deletionDepth > 0 && self::$resetNotifying) {
            // @ Reset is already dispatching; this nested notification only
            //   advances its causal generation and returns synchronously.
            TimerReset::notify();
         }
         self::drain();

         return true;
      }

      // @ Delete one task by id
      $Detached = [];
      foreach (array_keys(self::$tasks) as $runtime) {
         if (array_key_exists($id, self::$tasks[$runtime])) {
            $Detached[] = self::$tasks[$runtime][$id];
            unset(self::$tasks[$runtime][$id]);

            // @ Drop the runtime bucket once it empties — a stale empty bucket
            //   keeps `self::$tasks` non-empty, so `add()` would skip arming
            //   `pcntl_alarm()` after the task set fully drains (breaks any
            //   timer added later in the same worker, e.g. WS heartbeats).
            if (self::$tasks[$runtime] === []) {
               unset(self::$tasks[$runtime]);
            }
         }
      }

      // @ Delete status
      if ( array_key_exists($id, self::$status) ) {
         unset(self::$status[$id]);
      }

      // @ Reset timer alarm if no status
      if (empty(self::$status)) {
         pcntl_alarm(0);
      }
      self::defer($Detached);
      self::drain();

      return true;
   }

   /**
    * Queue detached values without releasing captures on a nested stack.
    *
    * @param array<mixed> $Values
    */
   private static function defer (array &$Values): void
   {
      if ($Values === []) {
         return;
      }
      if (isSet(self::$ReleaseQueue) === false) {
         /** @var SplQueue<array<mixed>> $ReleaseQueue */
         $ReleaseQueue = new SplQueue;
         self::$ReleaseQueue = $ReleaseQueue;
      }
      self::$ReleaseQueue->enqueue($Values);
      $Values = [];
   }

   /** Release bounded deletion generations, then restore reset owners once. */
   private static function drain (): void
   {
      if (self::$deletionDepth > 0) {
         return;
      }
      self::$deletionDepth++;
      try {
         $remaining = self::RELEASE_BUDGET;
         do {
            while (
               isSet(self::$ReleaseQueue)
               && self::$ReleaseQueue->isEmpty() === false
               && $remaining > 0
            ) {
               $remaining--;
               $Values = self::$ReleaseQueue->dequeue();
               try {
                  self::release($Values);
               }
               catch (Throwable) {
                  // Commit precedes release; preserve anything not yet detached.
                  self::defer($Values);
               }
            }

            if (self::$resetPending) {
               self::$resetPending = false;
               self::$resetNotifying = true;
               $notified = false;
               try {
                  TimerReset::notify();
                  $notified = true;
               }
               catch (Throwable) {
                  // A later timer touch retries the owner notification.
               }
               finally {
                  // Nested full resets notified Reset directly while it was
                  // dispatching, so a successful pass already coalesced them.
                  self::$resetPending = $notified === false;
                  self::$resetNotifying = false;
               }
            }
         }
         while (
            $remaining > 0
            && (
               (isSet(self::$ReleaseQueue) && self::$ReleaseQueue->isEmpty() === false)
               || self::$resetPending
            )
         );
      }
      finally {
         self::$deletionDepth--;
      }
   }

   /**
    * Release detached task/callback values without leaking destructor failures.
    *
    * @param array<mixed> $Values
    */
   private static function release (array &$Values, int $depth = 0): void
   {
      while ($Values !== []) {
         $Value = array_pop($Values);
         if (is_array($Value) && $depth < 8) {
            self::release($Value, $depth + 1);
         }
         try {
            unset($Value);
         }
         // @phpstan-ignore-next-line Detached captures may own throwing destructors.
         catch (Throwable) {
            // Core timer state is already committed before user destruction.
         }
      }
      if ($depth === 0) {
         try {
            gc_collect_cycles();
         }
         catch (Throwable) { // @phpstan-ignore catch.neverThrown
            // A deferred capture destructor cannot escape Timer::del().
         }
      }
   }
}
