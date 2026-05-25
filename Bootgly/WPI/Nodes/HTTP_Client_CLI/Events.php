<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI;


use Bootgly\WPI\Event;


enum Events : string implements Event
{
   case WorkerStarted = 'workerStarted';
   case ClientConnect = 'clientConnect';
   case ClientDisconnect = 'clientDisconnect';
   case DataRead = 'dataRead';
   case DataWrite = 'dataWrite';
   case ResponseReceive = 'responseReceive';
}
