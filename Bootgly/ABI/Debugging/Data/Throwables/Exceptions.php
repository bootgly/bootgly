<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Data\Throwables;


use Bootgly\ABI\Debugging\Data\Throwables;


abstract class Exceptions extends Throwables
{
   // * Config
   #public static bool $debug = false;
   #public static bool $print = true;
   #public static bool $return = false;
   #public static bool $exit = true;
   // * Data
   protected static array $exceptions = [];


   public static function collect (\Error|\Exception $E)
   {
      self::$exceptions[] = $E;
   }

   public static function debug (...$Throwables)
   {
      $exceptions = $Throwables ?: self::$exceptions;

      foreach ($exceptions as $Exception) {
         if ($Exception instanceof \Throwable) {
            self::report($Exception);
         }
      }
   }
}
