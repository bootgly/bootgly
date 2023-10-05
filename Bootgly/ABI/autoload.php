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


// @ Debugging
// Backtrace

// Errors
set_error_handler(
   callback: Debugging\Errors::collect(...),
   error_levels: E_ALL | E_STRICT
);

// Exceptions
set_exception_handler(
   callback: Debugging\Exceptions::collect(...)
);

// Vars
if (function_exists('dump') === false) {
   function dump(...$vars)
   {
      Debugging\Vars::dump(...$vars);
   }
}
if (function_exists('dd') === false) { // dd = dump and die
   function dd(...$vars)
   {
      Debugging\Vars::$exit = true;
      Debugging\Vars::$debug = true;
      Debugging\Vars::dump(...$vars);
   }
}

// Shutdown
register_shutdown_function(
   Debugging\Shutdown::collect(...)
);
