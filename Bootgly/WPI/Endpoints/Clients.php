<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints;


use Bootgly\ABI\Configs as Configuring;
use Bootgly\API\Endpoints\Client;


interface Clients extends Client
{
   /**
    * Configure the Client.
    *
    * Every concern arrives as its own Configs value object, in any order.
    *
    * @param Configuring ...$Configs One Configs per concern.
    *
    * @return self The Client instance, for chaining.
    */
   public function configure (Configuring ...$Configs): self;
}
