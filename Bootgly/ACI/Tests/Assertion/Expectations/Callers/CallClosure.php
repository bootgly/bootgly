<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Callers;


use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Caller;


class CallClosure extends Caller
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      $callable = $actual;

      if (is_callable($callable) === false) {
         return false;
      }

      return true;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      return new Fallback(
         format: "Failed asserting that the Closure is callable.",
         values: []
      );
   }
}
