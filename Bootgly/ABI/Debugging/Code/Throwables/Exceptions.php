<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Code\Throwables;


use Bootgly\ABI\Debugging;
use Bootgly\ABI\Debugging\Code\Throwables;


abstract class Exceptions extends Throwables implements Debugging
{
   // * Data
   protected static array $exceptions = [];


   public static function collect (\Error|\Exception $E)
   {
      self::$exceptions[] = $E;
   }

   public static function debug (...$Throwables)
   {
      foreach ($Throwables as $E) {
         self::report($E);
      }
   }
}
