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


use AssertionError;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\In;


/**
 * @property mixed $expectation
 */
trait Finders
{
   /**
    * Find the $needle in the $haystack.
    * "expect that $needle find $haystack".
    * The $haystack must be an array, object, or string.
    *
    * @param \Bootgly\ACI\Tests\Assertion\Auxiliaries\In $haystack
    * @param mixed $needle
    *
    * @throws \AssertionError
    *
    * @return self Returns the current instance for method chaining.
    */
   public function find (In $haystack, mixed $needle): self
   {
      $this->expectation = match ($haystack) {
         In::ArrayKeys =>
            new Finders\InArrayKeys($needle),
         In::ArrayValues =>
            new Finders\InArrayValues($needle),

         In::ClassesDeclared =>
            new Finders\InClassesDeclared($needle),
         In::InterfacesDeclared =>
            new Finders\InInterfacesDeclared($needle),

         In::ObjectProperties =>
            new Finders\InObjectProperties($needle),
         In::ObjectMethods =>
            new Finders\InObjectMethods($needle),

         In::TraitsDeclared =>
            new Finders\InTraitsDeclared($needle),

         default =>
            throw new AssertionError('Invalid finder.')
      };

      return $this;
   }
}
