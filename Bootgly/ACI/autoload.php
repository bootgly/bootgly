<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

#namespace Bootgly\ACI;

use Bootgly\ACI\Debugger;


if (function_exists('debug') === false) {
   function debug (...$vars)
   {
      $Debugger = new Debugger(...$vars);

      return $Debugger;
   }
}
if (function_exists('dd') === false) {
   function dd (...$vars)
   {
      Debugger::$exit = true;

      $Debugger = new Debugger(...$vars);

      return $Debugger;
   }
}
