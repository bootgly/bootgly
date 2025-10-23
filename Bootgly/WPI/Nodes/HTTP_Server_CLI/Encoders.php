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

use Bootgly\WPI\Endpoints\Encoder;
use Bootgly\WPI\Connections\Packages;


abstract class Encoders implements Encoder
{
   abstract public static function encode (Packages $Package, null|int &$length): string;
}
