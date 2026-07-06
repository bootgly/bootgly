<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events;


use Fiber;


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
    * When $value is null, the Fiber is tick-based: resumed every iteration.
    *
    * @param Fiber<mixed, mixed, mixed, mixed> $Fiber
    * @param mixed $value The suspended value from Fiber::start() or resume().
    * @param int $flag SCHEDULE_READ (default) or SCHEDULE_WRITE for I/O-bound Fibers.
    *
    * @return bool
    */
   public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool;
}
