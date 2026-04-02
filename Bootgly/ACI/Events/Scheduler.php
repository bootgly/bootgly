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
   /**
    * Schedule a suspended Fiber for resumption in the event loop.
    *
    * When $value is a stream resource, the Fiber becomes I/O-bound:
    * it will only resume when stream_select() signals readiness.
    * When $value is null, the Fiber is tick-based: resumed every iteration.
    *
    * @param Fiber<mixed, mixed, mixed, mixed> $Fiber
    * @param mixed $value The suspended value from Fiber::start() or resume().
    *
    * @return bool
    */
   public function schedule (Fiber $Fiber, mixed $value = null): bool;
}
