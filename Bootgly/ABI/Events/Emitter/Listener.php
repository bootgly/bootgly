<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events\Emitter;


use Bootgly\ABI\Events\Emission;


interface Listener
{
   /**
    * Handle one event dispatch.
    */
   public function handle (Emission $Emission): void;
}
