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
use Bootgly\API\Endpoints\Server;


interface Servers extends Server
{
   /** The account a root launch demotes to when none is configured — the kit image creates it. */
   public const string RUNTIME_USER = 'bootgly';

   /**
    * Configure the Server.
    *
    * Every concern arrives as its own Configs value object, in any order.
    *
    * @param Configuring ...$Configs One Configs per concern.
    *
    * @return self The Server instance, for chaining.
    */
   public function configure (Configuring ...$Configs): self;
}
