<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectation;


use Bootgly\ACI\Tests\Asserting;


/**
 * Waiter are for assertions that wait for a condition.
 * 
 * Use both $actual and $expected in the assertion.
 * The $expected is the value to wait for.
 */
abstract class Waiter implements Asserting
{
   // * Config
   public int|float $timeout;
   public array $arguments;


   /**
    * Wait for a condition.
    * 
    * @param int|float $timeout
    * @param array<mixed> $arguments
    */
   public function __construct (int|float $timeout, array $arguments)
   {
      $this->timeout = $timeout;
      $this->arguments = $arguments;
   }
}
