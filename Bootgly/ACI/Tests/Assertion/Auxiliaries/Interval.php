<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Auxiliaries;

/*
 * Enum to be used in boundaries of the Assertion.
 * e.g. $Assertion->expect($actual)->bound($from, $to, Interval::Closed);
 */
enum Interval
{
   case Closed;
   case Open;

   case LeftOpen;
   case RightOpen;
}
