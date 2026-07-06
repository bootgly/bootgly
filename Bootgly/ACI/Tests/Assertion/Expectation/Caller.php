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


use Bootgly\ACI\Tests\Asserting;


/**
 * Callers are for assertions that require a call on $actual.
 * 
 * Use $actual (callable) and $arguments as input in the assertion.
 * 
 * No output.
 */
abstract class Caller implements Asserting
{
   // * Config
   /** @var array<mixed> */
   public array $arguments;


   public function __construct (mixed ...$arguments)
   {
      $this->arguments = $arguments;
   }
}
