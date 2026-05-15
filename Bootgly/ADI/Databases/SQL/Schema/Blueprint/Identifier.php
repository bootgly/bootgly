<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema\Blueprint;


use function hash;
use function strlen;
use function substr;


/**
 * SQL identifier naming helper for generated schema names.
 */
class Identifier
{
   public const int LIMIT = 63;


   /**
    * Limit one generated identifier with a stable hash suffix.
    */
   public static function limit (string $name): string
   {
      if (strlen($name) <= self::LIMIT) {
         return $name;
      }

      $hash = substr(hash('sha256', $name), 0, 8);
      $prefix = substr($name, 0, self::LIMIT - 9);

      return "{$prefix}_{$hash}";
   }
}
