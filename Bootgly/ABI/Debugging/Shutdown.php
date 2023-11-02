<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging;


use Bootgly\ABI\Debugging;
use Bootgly\ABI\Debugging\Data\Throwables\Errors;
use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;


abstract class Shutdown implements Debugging
{
   // * Config
   public static bool $debug = true;
   #public static bool $print = true;
   #public static bool $return = false;
   #public static bool $exit = true;
   // * Data
   protected static ? array $error = [];


   public static function collect ($args = 0) : bool
   {
      $error = error_get_last();
      if ($error === NULL) {
         return false;
      }

      self::$error = $error;

      // TODO with $error
      #dump($error);

      return true;
   }

   public static function debug (...$Throwables)
   {
      if (self::$debug === true) {
         // TODO with self::$error
         Errors::debug();
         Exceptions::debug();
      }
   }
}
