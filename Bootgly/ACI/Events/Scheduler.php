<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
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
