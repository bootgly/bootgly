<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion;


use Bootgly\ACI\Tests\Assertion\Comparator;


interface Matcher extends Comparator
{
   // * Metadata
   public array $matches {
      get;
      set;
   }
}
