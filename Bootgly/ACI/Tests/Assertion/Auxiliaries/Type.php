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
 * e.g. $Assertion->expect($actual)->to->validate(Type::String);
 */
enum Type
{
   case Array;     // OK
   case Boolean;   // OK
   case Callable;  // OK
   case Countable; // OK
   case Float;     // OK
   case Integer;   // OK
   case Iterable;  // OK
   case Null;      // OK
   case Number;    // OK
   case Numeric;   // OK
   case Object;    // OK
   case Resource;  // OK
   case Scalar;    // OK
   case String;    // OK
}
