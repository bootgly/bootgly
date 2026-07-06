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
 * Enum to be used in finding values in a set.
 * e.g. $Assertion->expect($actual)->find(In::ArrayKeys, $needle);
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
