<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

#namespace Bootgly\ABI; // TODO temp


use Bootgly\ABI\Debugging;
use Bootgly\ABI\Debugging\Backtrace;

// @ Debugging
// Backtrace

// Errors
\set_error_handler(
   callback: Debugging\Errors::collect(...),
   error_levels: E_ALL | E_STRICT
);

// Exceptions
\set_exception_handler(
   callback: Debugging\Exceptions::collect(...)
);

// Shutdown
\register_shutdown_function(
   callback: Debugging\Shutdown::collect(...)
);

// Vars
if (function_exists('dump') === false) {
   function dump (...$vars)
   {
      // * Data
      // + Backtrace
      Debugging\Vars::$Backtrace = new Backtrace;

      Debugging\Vars::debug(...$vars);
   }
}
if (function_exists('dd') === false) { // dd = dump and die
   function dd (...$vars)
   {
      // * Config
      Debugging\Vars::$exit = true;
      Debugging\Vars::$debug = true;
      // * Data
      // + Backtrace
      Debugging\Vars::$Backtrace = new Backtrace;

      Debugging\Vars::debug(...$vars);
   }
}
