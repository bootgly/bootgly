<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use Bootgly\WPI\Event;


enum Events : string implements Event
{
   case WorkerStarted = 'workerStarted';
   case ClientConnect = 'clientConnect';
   case ClientDisconnect = 'clientDisconnect';
   case DatagramRead = 'datagramRead';
   case DatagramWrite = 'datagramWrite';
}
