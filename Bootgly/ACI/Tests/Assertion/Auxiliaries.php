<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion;


use Bootgly\ACI\Tests\Assertion\Auxiliaries\Interval;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Typehiting;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Value;


enum Auxiliaries
{
   case Interval = Interval::Closed;
   case Type = Type::class;
   case Typehiting = Typehiting::class;
   case Value = Value::class;
}
