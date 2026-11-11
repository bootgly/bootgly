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
use InvalidArgumentException;


/**
 * Optional capability of a Scheduler: recurring callbacks owned by the loop.
 *
 * A loop that implements it is detected with `instanceof`, as with
 * `Contextualizing` and `Cancelling`; `Scheduler` itself gains no method, so a
 * loop written before this capability existed stays valid — its consumers keep
 * the one-shot `defer()` or the static `Timer`.
 */
interface Repeating extends Scheduler
{
   /**
    * Register a callback that runs every `$interval` seconds until cancelled.
    *
    * The interval is a duration in seconds on the monotonic clock: `0.1` is
    * 100 ms, `1` is one second. It is not the `float|int` pair of `defer()`,
    * where the type selects a clock domain.
    *
    * Schedule — a fixed grid anchored at registration: run `k` is due at
    * `registration + k × interval`, so the callback's own duration and the
    * loop's latency never accumulate as drift. A run the loop reaches late
    * still runs once; the grid points that passed meanwhile are skipped, never
    * replayed as a burst, and the next run is the first grid point after the
    * instant the late run was dispatched.
    *
    * Lifecycle — the returned id shares the id space of `defer()` and is
    * cancelled with `cancel()`. A callback may cancel itself or any other
    * timer: a recurring callback cancelled earlier in a pass never runs in
    * that pass. One registered inside a callback first runs on a later pass.
    * A callback that throws is reported and keeps its schedule.
    *
    * @param float $interval Seconds between runs. Positive and finite; an
    *    implementation raises an interval below its resolution up to it.
    * @param Closure():void $Callback Runs on the loop, outside any Fiber.
    *
    * @throws InvalidArgumentException When the interval is zero, negative,
    *    not a number, infinite, or too long to be scheduled.
    *
    * @return int The timer id, for `cancel()`.
    */
   public function repeat (float $interval, Closure $Callback): int;
}
