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
 * Enum to be used in the type expected by the Assertion.
 * e.g. $Assertion->expect($actual)->be(Type::String);
 */
enum Type
{
   case String;
   case Int;
   case Float;
   case Bool;
   case Array;
   case Object;
   case Callable;
   case Iterable;
   case Null;
   case Mixed;
   case Numeric;
   case Scalar;
   case Resource;
}
