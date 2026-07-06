<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
