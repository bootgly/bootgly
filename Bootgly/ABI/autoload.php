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


use Bootgly\ABI\Debugging\Backtrace;
use Bootgly\ABI\Debugging\Data\Throwables\Errors;
use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Debugging\Shutdown;


// @ Debugging
// Backtrace

// Errors
\set_error_handler(
   callback: Errors::collect(...),
   error_levels: E_ALL | E_STRICT
);

// Exceptions
\set_exception_handler(
   callback: Exceptions::collect(...)
);

// Shutdown
\register_shutdown_function(
   callback: Shutdown::debug(...)
);

// Vars
if (function_exists('dump') === false) {
   function dump (...$vars)
   {
      // * Data
      // + Backtrace
      Vars::$Backtrace = new Backtrace;

      Vars::debug(...$vars);
   }
}
if (function_exists('dd') === false) { // dd = dump and die
   function dd (...$vars)
   {
      // * Config
      Vars::$exit = true;
      Vars::$debug = true;
      // * Data
      // + Backtrace
      Vars::$Backtrace = new Backtrace;

      Vars::debug(...$vars);
   }
}
