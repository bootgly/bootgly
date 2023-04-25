<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Event;


interface Loops
{
   public function add ($data, int $flag, $payload);
   public function del ($data, int $flag);

   public function loop ();
   public function destroy ();
}
