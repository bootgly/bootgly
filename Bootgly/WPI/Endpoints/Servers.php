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


use Bootgly\API\Endpoints\Server;


interface Servers extends Server
{
   public function configure (string $host, int $port, int $workers): self;
}
