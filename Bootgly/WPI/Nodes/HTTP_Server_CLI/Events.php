<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use Bootgly\WPI\Event;


enum Events : string implements Event
{
   case RequestReceived = 'requestReceived';
   case ServerAdvertised = 'serverAdvertised';
   case ServerStarted = 'serverStarted';
   case ServerStopped = 'serverStopped';
}
