<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


interface Assertion // Implementation/Repository
{
   // # Fallback
   public const array FALLBACK_TEMPLATE_VALUES_0 = [
      'actual' => "\033[93mactual\033[0m",
      'expected' => "\033[93mexpected\033[0m"
   ];

   // # Fallback
   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array;
}
