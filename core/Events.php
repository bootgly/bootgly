<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;

// TODO REFACTOR
interface Events
{
   const EV_READ = 1;
   const EV_WRITE = 2;
   const EV_EXCEPT = 3;
   const EV_SIGNAL = 4;
   const EV_TIMER = 8;
   const EV_TIMER_ONCE = 16;

   /**
    * Add event listener to event loop.
    *
    * @param mixed    $fd
    * @param int      $flag
    * @param callable $func
    * @param array    $args
    * @return bool
    */
   public function add ($fd, $flag, $func, $args = []);

   /**
    * Remove event listener from event loop.
    *
    * @param mixed $fd
    * @param int   $flag
    * @return bool
    */
   public function del ($fd, $flag);

   public function clearAllTimer ();
   public function getTimerCount ();

   public function loop ();
   public function destroy ();
}