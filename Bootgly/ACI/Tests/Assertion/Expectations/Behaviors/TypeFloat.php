<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations\Behaviors;


use function is_float;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Behavior;


/**
 * Validate if $actual is a float.
 */
class TypeFloat extends Behavior
{
   public function assert (mixed &$actual, mixed &$expected): bool
   {
      return is_float($actual);
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      return new Fallback(
         'Failed asserting that %s is a float.',
         [
            'actual' => $actual
         ],
         $verbosity
      );
   }
}