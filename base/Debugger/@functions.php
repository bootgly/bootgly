<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2017-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

#namespace Debugger;

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
