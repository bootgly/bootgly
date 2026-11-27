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
    * instant the loop read its clock for the pass that dispatched the late
    * run. A loop reads it once per pass, before that pass's callbacks: a
    * callback the pass reaches after another grid point went by runs again
    * at that point, on the next pass.
    *
    * Slack — with a `$slack` the grid starts later: its first point is the
    * first multiple of the slack, on the loop's clock, at or after
    * `registration + interval`. Timers that name the same slack start on
    * shared instants and wake the loop once for all of them, where each
    * would wake it on its own, and keep sharing them when their interval is
    * a whole multiple of the slack; the first run is up to one slack late,
    * never early, and the runs after it keep the interval.
    *
    * Due instant — each run receives the grid point it was due at, in
    * monotonic `hrtime(true)` nanoseconds: `now − due` is how late the loop
    * reached it, however many passes or callbacks held it.
    *
    * Lifecycle — the returned id shares the id space of `defer()` and is
    * cancelled with `cancel()`. A callback may cancel itself or any other
    * timer: a recurring callback cancelled earlier in a pass never runs in
    * that pass. One registered inside a callback first runs on a later pass.
    * A callback that throws is reported and keeps its schedule.
    *
    * @param float $interval Seconds between runs. Positive and finite; an
    *    implementation raises an interval below its resolution up to it.
    * @param Closure(int):void $Callback Runs on the loop, outside any Fiber,
    *    with the monotonic nanosecond instant the run was due at. A callback
    *    that does not need it may declare no parameter; a built-in function
    *    that takes none must be wrapped in a closure by the caller. An
    *    implementation passes this instant to every run (`Probes\Lag`
    *    depends on it).
    * @param float $slack Seconds the first run may be late to share its
    *    wakeups with the timers of the same slack; zero (the default) keeps
    *    the grid anchored at registration. An implementation raises a slack
    *    below its resolution up to it and takes one above the interval as
    *    the interval.
    *
    * @throws InvalidArgumentException When the interval is zero, negative,
    *    not a number, infinite, or too long to be scheduled, or the slack is
    *    negative, not a number or infinite.
    *
    * @return int The timer id, for `cancel()`.
    */
   public function repeat (float $interval, Closure $Callback, float $slack = 0.0): int;
}
