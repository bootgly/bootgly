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

use Bootgly\ABI\Debugging\Data\Throwables;
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
   /** Last arm stamp handed out: every add() and every re-arm takes the next one. */
   private static int $sequence = 0;
   /**
    * Arm stamp of each live task: a tick skips any task armed after it began.
    *
    * @var array<int,int>
    */
   private static array $stamps = [];
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
   /** Maximum generations of release failures one release() call re-releases. */
   private const int GENERATION_BUDGET = 64;
   /**
    * Release failures of a chain deeper than the budget, parked instead of
    * released: an endless chain of throwing destructors leaks one Throwable per
    * release call and never crashes the worker. They stay until the process
    * exits — where PHP destroys them, and a throwing destructor then makes the
    * exit status 255.
    *
    * @var array<int,Throwable>
    */
   // @phpstan-ignore property.onlyWritten (held, never read: keeping them alive is the point)
   private static array $Parked = [];


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
      self::$stamps[self::$id] = ++self::$sequence;
      self::$tasks[$runtime][self::$id] = [
         $interval, $handler, $args, $persistent
      ];

      return self::$id;
   }

   /**
    * Tick the timer, executing due tasks.
    *
    * Each due task runs at most once per tick, and a task deleted by an earlier
    * handler of the same tick never runs. A task added or re-armed during the
    * tick first runs on a later one. A handler failure is reported through
    * `Throwables::notify()` with `['origin' => 'timer', 'id' => $id]` (once per
    * Throwable instance): a persistent task keeps its schedule, a one-shot is
    * released. Values a handler leaves behind are released contained — a
    * throwing destructor, even one whose exception's own destructor throws,
    * never escapes into the SIGALRM handler.
    *
    * @return void
    */
   public static function tick (): void
   {
      self::drain();
      if ( empty(self::$tasks) ) {
         // ? A task is out of the wheel while its handler runs: a tick nested
         //   in that handler must not disarm the alarm the task re-arms under
         if (self::$status === []) {
            pcntl_alarm(0);
         }

         return;
      }

      pcntl_alarm(1);

      // ! The runtimes of this tick, and the last arm stamp handed out before it:
      //   a task armed after it — added by a handler, or re-armed by this tick
      //   or a nested one into a later bucket a blocking handler made due — is
      //   never a candidate of this tick (it could otherwise run twice). Ids are
      //   read from each bucket only when the loop reaches it: integers, never
      //   task or callback references, and no cost for buckets not yet due
      $runtimes = array_keys(self::$tasks);
      $mark = self::$sequence;
      // ! One release generation per tick: every value a handler leaves behind
      //   (a finished task, a failure) is released together by `drain()`
      $Detached = [];

      // @@ Run each due task that is still live, read from the live wheel
      foreach ($runtimes as $runtime) {
         if (time() < $runtime) {
            continue;
         }

         foreach (array_keys(self::$tasks[$runtime] ?? []) as $index) {
            // ? Armed after this tick began, or deleted by an earlier handler
            //   (`del($id)` or `del()`)
            if (
               (self::$stamps[$index] ?? 0) > $mark
               || isSet(self::$tasks[$runtime][$index]) === false
            ) {
               continue;
            }

            // @ Detach from the live wheel before the handler runs
            $Task = self::$tasks[$runtime][$index];
            unset(self::$tasks[$runtime][$index]);
            if (self::$tasks[$runtime] === []) {
               unset(self::$tasks[$runtime]);
            }

            try {
               call_user_func_array($Task[1], $Task[2]);
            }
            catch (Throwable $Throwable) {
               // @ Report the failure — never echo inside the SIGALRM handler —
               //   and keep a failing reporter from escaping the tick
               try {
                  Throwables::notify($Throwable, ['origin' => 'timer', 'id' => $index]);
               }
               catch (Throwable $Failure) {
                  $Detached[] = $Failure;
                  $Failure = null;
               }
               // ! Its trace may own captures: released with the rest
               $Detached[] = $Throwable;
               $Throwable = null;
            }
            finally {
               // @ Commit: re-arm a persistent task still live, else release it
               if ($Task[3] && ! empty(self::$status[$index])) {
                  self::$tasks[time() + $Task[0]][$index] = $Task;
                  self::$stamps[$index] = ++self::$sequence;
               }
               else {
                  if ($Task[3] === false) {
                     unset(self::$status[$index], self::$stamps[$index]);
                  }
                  $Detached[] = $Task;
               }
               $Task = null;
            }
         }
      }

      // : User destructors run inside `drain()`'s containment, never on this frame
      self::defer($Detached);
      self::drain();
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
         self::$stamps = [];
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
         unset(self::$status[$id], self::$stamps[$id]);
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
         // ! A failed owner notification is retried by a LATER timer touch,
         //   never again inside this drain (a failing owner would spin it)
         $failed = false;
         // ! Rounds are bounded apart from the release budget: no failure mode
         //   of an owner can keep this drain from returning
         $rounds = self::RELEASE_BUDGET;
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

            if (self::$resetPending && $failed === false) {
               self::$resetPending = false;
               self::$resetNotifying = true;
               $notified = false;
               $Failures = [];
               try {
                  TimerReset::notify();
                  $notified = true;
               }
               catch (Throwable $Failure) {
                  // A later timer touch retries the owner notification
                  $Failures[] = $Failure;
                  $Failure = null;
               }
               finally {
                  // Nested full resets notified Reset directly while it was
                  // dispatching, so a successful pass already coalesced them.
                  self::$resetPending = $notified === false;
                  self::$resetNotifying = false;
                  $failed = $notified === false;
               }
               // @ Released contained once the dispatch is over: a destructor
               //   asking for another reset only marks it pending
               self::release($Failures);
            }
         }
         while (
            $remaining > 0
            && --$rounds > 0
            && (
               (isSet(self::$ReleaseQueue) && self::$ReleaseQueue->isEmpty() === false)
               || (self::$resetPending && $failed === false)
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
    * The failures one generation of releases raises are released as the next
    * generation — never on their catch frame, where their own throwing
    * destructors would escape. Past `GENERATION_BUDGET` generations the chain
    * is parked instead: only an endless chain leaks, never a wide one.
    *
    * @param array<mixed> $Values
    */
   private static function release (array &$Values, int $depth = 0): void
   {
      $generation = 0;
      while ($Values !== []) {
         $Failures = [];
         while ($Values !== []) {
            $Value = array_pop($Values);
            try {
               if (is_array($Value) && $depth < 8) {
                  self::release($Value, $depth + 1);
               }
               unset($Value);
            }
            // @phpstan-ignore-next-line Detached captures may own throwing destructors.
            catch (Throwable $Failure) {
               // Core timer state is already committed before user destruction.
               $Failures[] = $Failure;
               $Failure = null;
            }
         }
         if ($depth === 0) {
            try {
               gc_collect_cycles();
            }
            catch (Throwable $Failure) { // @phpstan-ignore catch.neverThrown
               // A deferred capture destructor cannot escape Timer::del().
               $Failures[] = $Failure;
               $Failure = null;
            }
         }

         // @ The next generation: this one's failures — parked past the budget
         if (++$generation > self::GENERATION_BUDGET) {
            foreach ($Failures as $Failure) {
               self::$Parked[] = $Failure;
            }
            $Failure = null;
            $Failures = [];
         }
         $Values = $Failures;
         $Failures = [];
      }
   }
}
