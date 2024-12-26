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


use Bootgly\ACI\Tests\Asserting\Actual;
use Bootgly\ACI\Tests\Asserting\Expected;
use function count;
use AssertionError;

use Bootgly\ACI\Tests\Asserting;


trait Expectation
{
   use Actual;
   use Expected;


   // * Data
   /**
    * The expectations stack.
    * 
    * @var array<Asserting>
    */
   protected array $expectations = [];

   // * Metadata
   /**
    * Expecting a new expectation?
    * 
    * @var bool $expecting
    */
   protected bool $expecting = false;
   /**
    * Reset the last expectation
    * 
    * @var bool $reset
    */
   protected bool $reset = false;


   protected function get (): Asserting|null
   {
      if ($this->expectations === null) {
         return null;
      }

      return $this->expectations[
         count($this->expectations) - 1
      ];
   }
   protected function push (Asserting $Expectation): void
   {
      // !
      $not_expecting = $this->expecting === false;
      $is_asserting = $Expectation instanceof Asserting;
      // ?
      if ($not_expecting && $is_asserting) {
         throw new AssertionError('You need to use `->to` before set any expectation.');
      }

      $this->expectations[] = $Expectation;

      $this->expecting = false;
   }
   protected function reset (): void
   {
      $this->expectations = [];
      $this->reset = false;
   }
}
