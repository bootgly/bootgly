<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events;


use Closure;
use Fiber;
use Throwable;


interface Scheduler
{
   // @ I/O flags for Fiber scheduling
   public const int SCHEDULE_READ = 1;
   public const int SCHEDULE_WRITE = 2;

   /**
    * Suspend sentinel: the Fiber detached itself from the scheduler.
    *
    * A pooled worker Fiber suspends with this value after finishing its job
    * (it already parked itself back into its pool) — the event loop must
    * drop it instead of queueing it for resumption.
    */
   public const string DETACH = "\x00bootgly.fiber.detach\x00";

   /**
    * Schedule a suspended Fiber for resumption in the event loop.
    *
    * When $value is a stream resource, the Fiber becomes read I/O-bound:
    * it will only resume when stream_select() signals read readiness.
    * When $value is a Readiness object, the Fiber becomes read/write I/O-bound
    * according to Readiness::$flag.
    * Other suspended values are tick-based: resumed every iteration.
    *
    * @param Fiber<mixed, mixed, mixed, mixed> $Fiber
    * @param mixed $value The suspended value from Fiber::start() or resume().
    * @param int $flag SCHEDULE_READ (default) or SCHEDULE_WRITE for I/O-bound Fibers.
    *
    * @return bool False when the Fiber is terminal/detached or an explicit
    *    I/O wait was rejected without retention by the scheduler.
    */
   public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool;

   /**
    * Register a one-shot callback. The clock domain is selected by type:
    * a float is a wall-clock `microtime(true)` deadline in seconds; an int
    * is a monotonic `hrtime(true)` deadline in nanoseconds.
    */
   public function defer (float|int $deadline, Closure $Callback): int;

   /** Cancel a one-shot callback before it fires. */
   public function cancel (int $ID): bool;

   /**
    * Deliver a Throwable at the suspension point of one scheduled Fiber.
    *
    * The Fiber leaves every wait seat it occupies, is resumed with
    * `Fiber::throw()` inside its execution-segment binding, and its next
    * suspend value is queued again. Its generation is left untouched, so the
    * Fiber's own catch/finally may still select an outcome. A terminal
    * generation is never resumed — it is evicted instead.
    *
    * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
    *
    * @return bool True when the Throwable was delivered; false when the Fiber
    *    is not parked under this scheduler (running, terminated, detached, or
    *    bound to an already terminal generation).
    */
   public function interrupt (Fiber $Fiber, Throwable $Throwable): bool;
}
