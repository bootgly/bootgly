<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Asserting;


use Closure;

use Bootgly\ACI\Tests\Asserting\Actual;
use Bootgly\ACI\Tests\Asserting\Output;


abstract class Subassertion implements Output
{
   use Actual;


   // * Config
   public ?Closure $subassertion = null {
      get => $this->subassertion;
      set => $this->subassertion = $value;
   }
}
