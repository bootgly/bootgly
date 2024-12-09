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


use Exception;

use Bootgly\ACI\Tests\Asserting\Fallback;
use Bootgly\ACI\Tests\Assertion\Expectation\Thrower;


class ThrowException extends Thrower
{
   // * Config
   // ..Thrower


   public function assert (mixed &$actual, mixed &$expected): bool
   {
      // !
      $exception = $this->exception;
      $arguments = $this->arguments;

      // @
      try {
         $actual(...$arguments);
      }
      catch (Exception $E) {
         return $E instanceof $exception;
      }

      return false;
   }

   public function fail (mixed $actual, mixed $expected, int $verbosity = 0): Fallback
   {
      // !
      $exception = $this->exception;

      if (is_object($exception)) {
         $exception = get_class($exception);
      }

      // :
      return new Fallback(
         'Failed asserting that the exception %s was thrown.',
         [
            'expected' => $exception
         ],
         $verbosity
      );
   }
}