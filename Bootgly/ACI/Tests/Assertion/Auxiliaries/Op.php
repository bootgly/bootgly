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
 * Enum to be used in comparison of values.
 * e.g. $Assertion->expect($actual, Op::GreaterOrEqual, $expected);
 */
enum Op
{
   case Equal;
   case NotEqual;

   case Identical;
   case NotIdentical;

   case GreaterThan;
   case LessThan;

   case GreaterThanOrEqual;
   case LessThanOrEqual;
}
