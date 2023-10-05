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
use Bootgly\ABI\Debugging\Errors\Handler as ErrorHandler;
#set_error_handler(callback: ErrorHandler::handle(...), error_levels: E_ALL | E_STRICT);

// Exceptions
use Bootgly\ABI\Debugging\Exceptions\Handler as ExceptionHandler;
#set_exception_handler(callback: ExceptionHandler::handle(...));

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
#dd('Test');

// Shutdown
