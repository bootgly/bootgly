<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database\Connection;


/**
 * Database connection transport states.
 *
 * Backing values are connection-prefixed to keep logs unambiguous from
 * operation-state values while preserving type-level separation.
 */
enum ConnectionStates: string
{
   case Idle = 'connection.idle';
   case Connecting = 'connection.connecting';
   case Encrypted = 'connection.encrypted';
   case Ready = 'connection.ready';
   case Startup = 'connection.startup';
   case SSLRequest = 'connection.ssl_request';
}
