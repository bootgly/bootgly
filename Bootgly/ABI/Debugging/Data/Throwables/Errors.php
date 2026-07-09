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


use function error_reporting;
use ErrorException;
use Throwable;

use Bootgly\ABI\Debugging\Data\Throwables;


abstract class Errors extends Throwables
{
   // * Config
   #public static bool $debug = true;
   #public static bool $exit = true;
   #public static bool $output = true;
   #public static bool $return = false;
   public static int $verbosity = 3;

   // * Data
   /** @var array<int|string,array<string,int|string>|int|string> */
   protected static array $errors = [];


   // @ Error
   public static function collect (int $level, string $message, string $filename, int $line): bool
   {
      // ?: Hot path: short-circuit when the error is suppressed (@-operator sets error_reporting to 0).
      // Skipping the array append avoids per-write overhead under heavy I/O (fwrite/fread warnings).
      if ( ! (error_reporting() & $level) ) {
         return false;
      }

      self::$errors[] = [
         'message'  => $message,
         'level'    => $level,
         'filename' => $filename,
         'line'     => $line
      ];

      throw new ErrorException($message, 0, $level, $filename, $line);
   }

   public static function debug (mixed ...$Throwables): void
   {
      $errors = $Throwables ?: self::$errors;

      foreach ($errors as $Error) {
         if ($Error instanceof Throwable) {
            self::report($Error);
         }
      }
   }
}
