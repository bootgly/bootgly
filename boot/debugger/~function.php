<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\Debugger;


function debug (...$vars)
{
   if (Debugger::$trace === null) {
      Debugger::$trace = debug_backtrace();
   }

   new Debugger(...$vars);

   if (Debugger::$trace !== false) {
      Debugger::$trace = null;
   }
}
