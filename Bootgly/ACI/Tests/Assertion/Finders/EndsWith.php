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


class EndsWith implements Finder
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
      $needle = $this->needle;

      return substr(
         string: (string) $actual,
         offset: -strlen($needle)
      ) === $needle;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): array
   {
      return [
         'format' => 'Failed asserting that the string "%s" ends with "%s".',
         'values' => [
            'actual' => $actual,
            'expected' => $this->needle
         ]
      ];
   }
}
