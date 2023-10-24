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
   // * Data
   protected static ? array $errors = [];


   public static function collect ($args = 0) : bool
   {
      $error = error_get_last();
      if ($error === NULL) {
         return false;
      }

      self::$errors[] = [...$error];
      // TODO

      return true;
   }

   public static function debug (...$Throwables)
   {
      Errors::debug();
      Exceptions::debug();
   }
}
