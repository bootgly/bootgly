<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI;


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
