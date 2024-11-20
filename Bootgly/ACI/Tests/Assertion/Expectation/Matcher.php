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


use Bootgly\ACI\Tests\Assertion\Comparator;
use Bootgly\ACI\Tests\Assertion\Expectation;


/**
 * Implementation      / Repository
 * Expectation/Matcher / Expectations/Matchers
 */
interface Matcher extends Expectation, Comparator
{
   // * Metadata
   public array $matches {
      get;
      set;
   }
}
