<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP\Server\CLI;


use Bootgly\WPI\Interfaces\TCP\Server\Packages;


abstract class Encoders
{
   abstract public static function encode(Packages $Package, &$size) : string;
}
