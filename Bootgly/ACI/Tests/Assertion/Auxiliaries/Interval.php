<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
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
