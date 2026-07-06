<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Throwers;


use function is_callable;
use Exception;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Thrower;


class ThrowException extends Thrower
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
      catch (Exception $Exception) {
         return $Exception instanceof $expected;
      }

      return false;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      // !
      $expected = $this->expected;

      // :
      return new Fallback(
         'Failed asserting that the exception `%s` was thrown.',
         [
            'expected' => $expected::class
         ],
         $verbosity
      );
   }
}
