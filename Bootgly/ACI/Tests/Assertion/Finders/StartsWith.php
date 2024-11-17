<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Finders;


use Bootgly\ACI\Tests\Assertion\Finder;


class StartsWith implements Finder
{
   public mixed $needle {
      get => $this->needle ??= null;
      set => $this->needle = $value;
   }


   public function __construct (mixed ...$values)
   {
      $this->needle = $values[0];
   }
   public function compare (mixed &$actual, mixed &$expected): bool
   {
      return strpos(
         haystack: (string) $actual,
         needle: (string) $this->needle
      ) === 0;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
   {
      return [
         'format' => 'Failed asserting that the string "%s" starts with "%s".',
         'values' => [
            'actual' => $actual,
            'expected' => $this->needle
         ]
      ];
   }
}