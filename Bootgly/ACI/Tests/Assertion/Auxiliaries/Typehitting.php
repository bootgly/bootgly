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
 * Enum to be used if the $actual can be converted to a certain type.
 * e.g. $Assertion->expect($actual)->to->convert(Typehiting::Truthy);
 */
enum Typehiting
{
   case Falsy; // null, false, 0, '', '0', []
   case Truthy;
   // ...
}
