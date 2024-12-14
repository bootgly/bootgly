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
 * Enum to be used with be() method in the Assertion.
 * e.g. $Assertion->expect($actual)->to->be(Value::Positive);
 */
enum Value
{
   case Empty;
   case NaN;

   // # Number
   case Even;         // OK
   case Infinite;
   case Negative;     // OK
   case Odd;          // OK
   case Positive;     // OK

   // # String
   case Lowercase;
   case Uppercase;
   case Alphanumeric;
   case Numeric;
   case Alpha;
   case Hexadecimal;
   case Binary;
   case Octal;
   case Base64;
   case Email;
   case URL;
   case IP;
   case MAC;
   case UUID;
   // # String - Hash
   case MD5;
   case SHA1;
   case SHA256;
   case SHA512;
}
