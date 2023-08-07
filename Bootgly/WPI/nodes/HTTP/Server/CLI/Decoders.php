<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\nodes\HTTP\Server\CLI;


use Bootgly\WPI\interfaces\TCP\Server\Packages;


abstract class Decoders
{
   abstract public static function decode(Packages $Package, string $buffer, int $size);
}
