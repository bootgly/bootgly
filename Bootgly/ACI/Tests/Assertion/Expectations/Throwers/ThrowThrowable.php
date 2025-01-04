<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Throwers;


use Throwable;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Thrower;


class ThrowThrowable extends Thrower
{
   // * Config
   // ..$expected
   // ..$arguments

   public function assert (mixed &$actual, mixed &$expected): bool
   {
      // ?
      if (is_callable($actual) === false) {
         return false;
      }

      // !
      $expected = $this->expected;
      $arguments = $this->arguments;

      // @
      try {
         $actual(...$arguments);
      }
      catch (Throwable $Throwable) {
         return $Throwable instanceof $expected;
      }

      return false;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      // !
      $expected = $this->expected;

      // :
      return new Fallback(
         'Failed asserting that the throwable `%s` was thrown.',
         [
            'expected' => $expected::class
         ],
         $verbosity
      );
   }
}
