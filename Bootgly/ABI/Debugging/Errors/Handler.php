<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Errors;


use Bootgly\ABI\Debugging\Errors;


class Handler extends Errors
{
   // * Config
   // ...

   // * Data
   // ...

   // * Meta
   // ...


   public static function handle (int $level, string $message, string $file, int $line) : bool
   {
      throw new \ErrorException($message, 0, $level, $file, $line);
      return false;
   }
}
