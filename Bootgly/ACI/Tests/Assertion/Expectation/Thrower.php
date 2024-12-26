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
 * Thrower are for assertions that throw an Throwable.
 * 
 * Use only $actual in the assertion.
 * The $actual is the value to throw an Throwable.
 */
abstract class Thrower implements Asserting
{
   // * Config
   public Throwable $expected;
   /** @var array<mixed> $arguments */
   public array $arguments;


   public function __construct (Throwable $expected, array $arguments)
   {
      $this->expected = $expected;
      $this->arguments = $arguments;
   }
}
