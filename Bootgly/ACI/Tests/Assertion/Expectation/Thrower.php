<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectation;


use Throwable;

use Bootgly\ACI\Tests\Asserting;


/**
 * Thrower are for assertions that throw an Throwable.
 * 
 * Use only $actual (callable) and $expected (Throwable) as input in the assertion.
 * The $expected is the value to be thrown by $actual.
 * 
 * It has a throwable as output.
 */
abstract class Thrower implements Asserting
{
   // * Config
   public Throwable $expected;
   /** @var array<mixed> $arguments */
   public array $arguments;


   /**
    * Thrower constructor.
    * 
    * @param Throwable $expected
    * @param array<mixed> $arguments
    */
   public function __construct (Throwable $expected, array $arguments)
   {
      $this->expected = $expected;
      $this->arguments = $arguments;
   }
}
