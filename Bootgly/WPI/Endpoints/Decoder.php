<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints;


use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;


interface Decoder
{
   public static function decode (Packages $Package, string $buffer, int $size): int;
}
