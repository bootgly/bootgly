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


use Bootgly\WPI\Endpoints\Decoder;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;


abstract class Decoders implements Decoder
{
   abstract public static function decode (Packages $Package, string $buffer, int $size): int;
}
