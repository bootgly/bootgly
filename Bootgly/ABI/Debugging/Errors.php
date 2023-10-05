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


abstract class Errors
{
   // * Data
   protected static array $errors = [];


   // @ Error
   public static function collect (int $level, string $message, string $filename, int $line) : bool
   {
      self::$errors[] = [
         'message'  => $message,
         'level'    => $level,
         'filename' => $filename,
         'line'     => $line
      ];

      if ( ! (error_reporting() & $level) ) {
         // This error code is not included in error_reporting, so let it fall
         // through to the standard PHP error handler
         return false;
      }

      throw new \ErrorException($message, 0, $level, $filename, $line);

      return true;
   }

   public static function dump (...$data)
   {
      // ...
   }
}
