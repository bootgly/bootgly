<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// @ Local namespace
namespace Bootgly\ABI {

   use Bootgly\ABI\Debugging\Data\Throwables\Errors;
   use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;

   use Bootgly\ABI\Debugging\Shutdown;


   // @ Debugging\Data\Errors
   \restore_error_handler();
   \set_error_handler(
      callback: Errors::collect(...),
      error_levels: E_ALL | E_STRICT
   );

   // @ Debugging\Data\Exceptions
   \restore_exception_handler();
   \set_exception_handler(
      callback: Exceptions::collect(...)
   );

   // @ Debugging\Shutdown
   \register_shutdown_function(
      callback: Shutdown::debug(...)
   );

   // @ IO\FS
   // functions
   if (\function_exists('\Bootgly\ABI\copy_recursively') === false) {
      function copy_recursively (string $source, string $destination)
      {
         if (\is_dir($source) === true) {
            \mkdir($destination);

            $paths = \scandir($source);

            foreach ($paths as $path) {
               if ($path !== '.' && $path !== '..') {
                  copy_recursively("$source/$path", "$destination/$path");
               }
            }
         }
         else if (\file_exists($source) === true) {
            \copy($source, $destination);
         }
      }
   }
}

// @ Global namespace
namespace {

   use Bootgly\ABI\Debugging\Backtrace;
   use Bootgly\ABI\Debugging\Data\Vars;

   // @ Debugging\Data\Vars
   // functions
   if (\function_exists('dump') === false) {
      function dump (...$vars)
      {
         // * Data
         // + Backtrace
         Vars::$Backtrace = new Backtrace;

         Vars::debug(...$vars);
      }
   }
   if (\function_exists('dd') === false) { // dd = dump and die
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
}
