<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use Bootgly\WPI\Interfaces\TCP\Server\Packages;


abstract class Decoders
{
   abstract public static function decode(Packages $Package, string $buffer, int $size) : int;
}
