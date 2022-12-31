<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Server;


use Bootgly\Web\Servers;


interface Connections
{
   public function __construct (Connection &$Connection);

   public function read (&$Socket);
   public function write (&$Socket);
}
