<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
