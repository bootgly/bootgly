<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI;


use Bootgly\WPI\Event;


enum Events : string implements Event
{
   case HandshakeRequested = 'handshakeRequested';
   case Connected = 'connected';
   case MessageReceived = 'messageReceived';
   case Disconnected = 'disconnected';
   case ServerStarted = 'serverStarted';
   case ServerStopped = 'serverStopped';
}
