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
 * Callers are for assertions that require a call on $actual.
 * 
 * Use $callable to specify the argument to call on $actual.
 * 
 * No output.
 */
abstract class Caller implements Asserting
{
   // * Config
   public array $arguments;


   public function __construct (mixed ...$arguments)
   {
      $this->arguments = $arguments;
   }
}
