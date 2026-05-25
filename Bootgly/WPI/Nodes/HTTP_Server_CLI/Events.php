<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use Bootgly\WPI\Event;


enum Events : string implements Event
{
   case RequestReceived = 'requestReceived';
   case ServerStarted = 'serverStarted';
   case ServerStopped = 'serverStopped';
}
