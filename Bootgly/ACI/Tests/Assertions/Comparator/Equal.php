<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertions\Comparator;


use Bootgly\ACI\Tests\Assertions\Comparator;


class Equal implements Comparator
{
   public function compare ($actual, $expected): bool
   {
      return $actual === $expected;
   }
}
