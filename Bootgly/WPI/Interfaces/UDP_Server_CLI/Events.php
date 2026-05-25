<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI;


use Bootgly\WPI\Event;


enum Events : string implements Event
{
   case DatagramReceive = 'datagramReceive';
}
