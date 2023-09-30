<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertions;


use Bootgly\ACI\Tests\Assertions;


class Assertion
{
   // * Config
   public static ? string $description = null;

   // * Data
   // ...

   // * Meta
   public static ? string $fallback = null;


   public function __construct (
      mixed $assertion,
      ? string $description = null,
      \Throwable|string|null $fallback = null
   )
   {
      // * Config
      self::$description = $description;

      // * Data
      // ...

      // * Meta
      self::$fallback = null;


      // @
      \assert($assertion, $fallback);
   }

   public function __destruct ()
   {
      // * Config
      self::$description = null;

      // * Meta
      self::$fallback = null;
   }
}
