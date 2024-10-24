<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Cases;


use function assert;
use Throwable;

use Bootgly\ACI\Tests\Assertions\Comparator;
use Bootgly\ACI\Tests\Assertions\Comparator\Equal;


class Assertion
{
   public const Comparator = Comparator::class;

   // * Config
   public static ?string $description = null;

   // * Data
   public static mixed $actual;
   public static mixed $expected;

   // * Metadata
   public static ?string $fallback = null;


   public function __construct (
      mixed $actual,
      mixed $expected,
      ?string $description = null,
      Throwable|string|null $fallback = null
   )
   {
      // * Config
      self::$description = $description;

      // * Data
      self::$actual = $actual;
      self::$expected = $expected;

      // * Metadata
      self::$fallback = $fallback ?: <<<FALLBACK
      $fallback
      Expected: $expected
      Actual: $actual
      FALLBACK;
   }

   public function __destruct ()
   {
      // * Config
      // self::$description = null;

      // * Metadata
      self::$fallback = null;
   }

   public function assert (?Comparator $Comparator = null): bool
   {
      // @
      if ($Comparator === null) {
         $Comparator = new Equal;
     }
 
     return assert(
         assertion: $Comparator->compare(self::$actual, self::$expected),
         description: self::$fallback
     );
   }
}
