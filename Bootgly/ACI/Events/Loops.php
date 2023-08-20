<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events;


interface Loops
{
   public function add ($data, int $flag, $payload);
   public function del ($data, int $flag);

   public function loop ();
   public function destroy ();
}
