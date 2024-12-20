<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion;


use AssertionError;


trait Expectation
{
   protected array $arguments;

   protected bool $expecting = false;
   protected ?array $expectations = null;

   protected function get (): mixed
   {
      // last expectation in the stack
      return $this->expectations[count($this->expectations) - 1];
   }
   protected function set (mixed $expectation): void
   {
      // !
      $notExpecting = $this->expecting === false;
      $isAsserting = $expectation instanceof Asserting;
      // ?
      if ($notExpecting && $isAsserting) {
         throw new AssertionError('You need to use `->to` before set any expectation.');
      }

      $this->expectations ??= [];
      $this->expectations[] = $expectation;

      $this->expecting = false;
   }
}
