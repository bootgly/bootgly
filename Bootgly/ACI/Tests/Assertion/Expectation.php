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
   protected bool $expecting = false;
   protected ?array $expectations = null;

   protected mixed $expectation {
      get { // last expectation in the stack
         return $this->expectations[count($this->expectations) - 1];
      }
      set (mixed $expectation) {
         // ?
         $notExpecting = $this->expecting === false;
         $isAsserting = $expectation instanceof Asserting;
         if ($notExpecting && $isAsserting) {
            throw new AssertionError('You need to use `->to` before set any expectation.');
         }

         $this->expectations ??= [];
         $this->expectations[] = $expectation;

         $this->expecting = false;
      }
   }
}
