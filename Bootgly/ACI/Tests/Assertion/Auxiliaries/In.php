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
 * Enum to be used in finding values in a set.
 * e.g. $Assertion->expect($actual)->find(In::ArrayKeys, $expected);
 */
enum In
{
   case ArrayKeys;
   case ArrayValues;

   case ClassesDeclared;
   case InterfacesDeclared;

   case ObjectProperties;
   case ObjectMethods;

   case TraitsDeclared;
}
