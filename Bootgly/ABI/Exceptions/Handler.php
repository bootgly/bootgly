<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Exceptions;


use Error;
use Exception;

use Bootgly\ABI\Exceptions;


class Handler extends Exceptions
{
   // * Config
   // ...

   // * Data
   // ...

   // * Meta
   // ...

   public static function handle (Error|Exception $E) {
      self::$exceptions[] = $E;
   }
}
