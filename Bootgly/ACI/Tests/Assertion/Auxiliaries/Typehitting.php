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
 * Enum to be used if the $actual can be converted to a certain type.
 * e.g. $Assertion->expect($actual)->be(Typehiting::Truthy);
 */
enum Typehiting
{
   case Falsy; // null, false, 0, '', '0', []
   case Truthy;
   // ...
}
