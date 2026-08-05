<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Auxiliaries;

/*
 * Enum to be used in comparison of values.
 * e.g. $Assertion->expect($actual, Op::GreaterOrEqual, $expected);
 */
enum Op: string
{
   case Equal = '==';
   case NotEqual = '!=';

   case Identical = '===';
   case NotIdentical = '!==';

   case GreaterThan = '>';
   case LessThan = '<';

   case GreaterThanOrEqual = '>=';
   case LessThanOrEqual = '<=';
}
