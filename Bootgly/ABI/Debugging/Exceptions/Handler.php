<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Exceptions;


use Bootgly\ABI\Debugging\Exceptions;


class Handler extends Exceptions
{
   // * Config
   // ...

   // * Data
   // ...

   // * Meta
   // ...

   public static function handle (\Error|\Exception $E) {
      self::$exceptions[] = $E;
   }
}
