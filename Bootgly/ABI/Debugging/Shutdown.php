<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging;


use const NULL;
use function error_get_last;

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
   /** @var array<string,int|string>|null */
   protected static ?array $error = [];


   public static function collect (): bool
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

   public static function debug (mixed ...$Throwables): void
   {
      if (self::$debug === true) {
         // TODO with self::$error
         Errors::debug();
         Exceptions::debug();
      }
   }
}
