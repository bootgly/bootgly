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


use Bootgly\WPI\Endpoints\Servers\Decoder\States;


interface Decoder
{
   public function decode (Packages $Package, string $buffer, int $size): States;
}
