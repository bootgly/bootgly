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


use Throwable;

use Bootgly\ACI\Tests\Asserting;


/**
 * Thrower are for assertions that throw an exception.
 * 
 * Use only $actual in the assertion.
 * The $actual is the value to throw an exception.
 */
abstract class Thrower implements Asserting
{
   // * Config
   public string|Throwable $exception;
   public mixed $arguments;


   public function __construct (string|Throwable $exception, mixed ...$arguments)
   {
      $this->exception = $exception;
      $this->arguments = $arguments;
   }
}
