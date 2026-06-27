<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Client_CLI;


use Bootgly\WPI\Event;


enum Events : string implements Event
{
   case Connected = 'connected';
   case MessageReceived = 'messageReceived';
   case Disconnected = 'disconnected';
}
