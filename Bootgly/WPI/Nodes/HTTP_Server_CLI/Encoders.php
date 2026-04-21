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

use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Endpoints\Servers\Packages;


abstract class Encoders implements Encoder
{
   /**
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
    */
   abstract public static function encode (Packages $Package, null|int &$length): string;
}
