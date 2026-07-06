<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Data\Throwables;


use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables;


abstract class Exceptions extends Throwables
{
   // * Config
   #public static bool $debug = true;
   #public static bool $exit = true;
   #public static bool $output = true;
   #public static bool $return = false;
   public static int $verbosity = 3;

   // * Data
   /** @var array<Throwable> */
   protected static array $exceptions = [];


   public static function collect (Throwable $E): void
   {
      self::$exceptions[] = $E;
   }

   public static function debug (mixed ...$Throwables): void
   {
      $exceptions = $Throwables ?: self::$exceptions;

      foreach ($exceptions as $Exception) {
         if ($Exception instanceof Throwable) {
            self::report($Exception);
         }
      }
   }
}
