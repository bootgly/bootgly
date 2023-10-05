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


abstract class Shutdown
{
   // * Data
   protected static ? array $errors = [];


   public static function collect () : bool
   {
      $error = error_get_last();
      if ($error === null) {
         return false;
      }

      self::$errors[] = [
         ...error_get_last()
      ];

      return true;
   }

   public static function dump (...$data)
   {
      // ...
   }
}
