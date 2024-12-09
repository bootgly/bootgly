<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Expectations;


use Bootgly\ACI\Tests\Assertion\Expectation;


/**
 * @property mixed $expectation
 */
trait Matchers
{
   /**
    * Match a string against a pattern.
    *
    * @param string $pattern The pattern to match against.
    *
    * @return self Returns the current instance for method chaining.
    */
   public function match (string $pattern): self
   {
      // @ Check if pattern is a valid path (dir or file without checking if it exists)
      if (preg_match('/^(\/[^\/ ]*)+\/?$/', $pattern)) {
         $this->expectation = new Matchers\VariadicDirPath($pattern);

         return $this;
      }

      $this->expectation = new Matchers\Regex($pattern);

      return $this;
   }
}
