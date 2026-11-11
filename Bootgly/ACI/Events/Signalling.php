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
use LogicException;


/**
 * Optional capability of a loop: signal handlers owned by the loop.
 *
 * A loop that implements it is detected with `instanceof`, as with
 * `Repeating` and `Cancelling`; `Loops` itself gains no method, so a loop
 * written before this capability existed stays valid — its process keeps the
 * signal handlers it installed by other means.
 */
interface Signalling extends Loops
{
   /**
    * Run a handler on the loop each time the process receives a signal.
    *
    * Dispatch — the handler runs synchronously on the loop, at the point of
    * a pass where the loop dispatches signals: between callbacks, never in
    * the middle of one. A signal received while a callback runs waits for
    * that point; a signal received while the loop waits wakes it. Whatever
    * the handler does to the loop — arming a timer, stopping the loop,
    * trapping or releasing a signal — takes effect in that same pass.
    *
    * Registration — one handler per signal: trapping a signal this loop
    * already holds replaces its handler. Signals are a property of the
    * process, so only one loop of a process holds a given signal at a time.
    * A handler trapped before a fork is copied into the child with the loop.
    *
    * Failure — a handler that throws is reported and the loop goes on: the
    * other signals pending in the same pass still reach their handlers.
    *
    * @param int $signal The signal number (`SIGTERM`, `SIGUSR1`, …).
    * @param Closure(int):void $Handler Receives the signal number. Runs on
    *    the loop, outside any Fiber.
    *
    * @throws InvalidArgumentException When the signal cannot be caught by a
    *    process (`SIGKILL`, `SIGSTOP`) or is not a signal number.
    * @throws LogicException When another loop of this process holds the
    *    signal, or when the handler installed for it is one the loop could
    *    not put back.
    */
   public function trap (int $signal, Closure $Handler): void;
   /**
    * Release a signal this loop trapped.
    *
    * The process goes back to what it did with the signal before this loop
    * trapped it — the handler that was installed then, or the default
    * action. A signal still pending reaches the handler that takes the
    * signal back; when what comes back is the default action or an ignore,
    * the pending signal is dropped. A signal the process inherited as
    * ignored may read as the default action, and then takes it once
    * released.
    *
    * @param int $signal The signal number.
    *
    * @return bool Whether this loop held the signal.
    */
   public function untrap (int $signal): bool;
}
