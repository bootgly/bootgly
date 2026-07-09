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


use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use function error_get_last;
use ErrorException;

use Bootgly\ABI\Debugging;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Debugging\Data\Throwables\Errors;
use Bootgly\ABI\Debugging\Data\Throwables\Exceptions;


abstract class Shutdown implements Debugging
{
   // * Config
   public static bool $debug = true;

   // * Data
   /** @var null|array<string,int|string> */
   protected static null|array $error = null;


   /**
    * Collect the last fatal error — or an injected one, for testability.
    *
    * @param null|array<string,int|string> $error
    */
   public static function collect (null|array $error = null): bool
   {
      // !
      $error ??= error_get_last();

      // ? Only fatal errors reach shutdown unhandled — everything else was
      // already converted to ErrorException by Errors::collect()
      $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
      if ($error === null || ((int) ($error['type'] ?? 0) & $fatal) === 0) {
         return false;
      }

      self::$error = $error;

      // :
      return true;
   }

   public static function debug (mixed ...$Throwables): void
   {
      // ?
      if (self::$debug === false) {
         return;
      }

      // @ Fatal errors bypass every handler — synthesize and report them here
      if (self::collect() === true && self::$error !== null) {
         $Fatal = new ErrorException(
            message: (string) (self::$error['message'] ?? ''),
            code: 0,
            severity: (int) (self::$error['type'] ?? E_ERROR),
            filename: (string) (self::$error['file'] ?? ''),
            line: (int) (self::$error['line'] ?? 0)
         );

         Throwables::notify($Fatal, ['origin' => 'fatal']);
         Errors::report($Fatal);
      }

      // @ Flush buffered throwables (already notified at intake)
      Errors::debug();
      Exceptions::debug();
   }
}
