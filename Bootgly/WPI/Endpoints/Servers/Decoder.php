<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Servers;


use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;


interface Decoder
{
   public function decode (Packages $Package, string $buffer, int $size): int;
}
