<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Matchers;


use Bootgly\ACI\Tests\Assertion\Matcher;


class RegexMatch implements Matcher
{
   public function compare (mixed &$actual, mixed &$expected): bool
   {
      return preg_match((string) $expected, (string) $actual) === 1;
   }

   public function fail (mixed $actual, mixed $expected): array
   {
      return [
         'format' => 'Failed asserting that %s matches the regex %s.',
         'values' => [
            'actual' => $actual,
            'expected' => $expected
         ]
      ];
   }
}
