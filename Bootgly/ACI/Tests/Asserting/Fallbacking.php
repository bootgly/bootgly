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


use Bootgly\ACI\Tests\Asserting\Fallback;


interface Fallbacking
{
   public const array FALLBACK_TEMPLATE_VALUES_0 = [
      'actual' => "\033[93mactual\033[0m",
      'expected' => "\033[93mexpected\033[0m"
   ];

   public function fail (
      mixed $actual, mixed $expected, int $verbosity = 0
   ): Fallback;
}
