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
 * Matchers are for assertions that match $actual with pattern ($expected).
 * 
 * Use both $actual and $expected (pattern) as input in the assertion.
 * The $expected is a pattern to match with $actual.
 * 
 * No output.
 */
abstract class Matcher implements Asserting
{
   // * Config
   public null|string $pattern;
   /** @var array<string> */
   public array $matches;


   public function __construct (null|string $pattern = null)
   {
      $this->pattern = $pattern;
   }
}
