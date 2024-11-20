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
 * Implementation     / Repository
 * Expectation/Finder / Expectations/Finders
 */
interface Finder extends Expectation, Comparator
{
   // * Data
   public string $needle {
      get;
      set;
   }
}
