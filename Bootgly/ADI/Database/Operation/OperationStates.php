<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database\Operation;


/**
 * Database operation and protocol states.
 *
 * Backing values are operation-prefixed to keep logs unambiguous from
 * connection-state values while preserving type-level separation.
 */
enum OperationStates: string
{
   case Pending = 'operation.pending';
   case Queued = 'operation.queued';
   case Connecting = 'operation.connecting';
   case Startup = 'operation.startup';
   case SSLRequest = 'operation.ssl_request';
   case SSLResponse = 'operation.ssl_response';
   case SSLHandshake = 'operation.ssl_handshake';
   case Authenticating = 'operation.authenticating';
   case Password = 'operation.password';
   case Querying = 'operation.querying';
   case Reading = 'operation.reading';
   case Finished = 'operation.finished';
   case Failed = 'operation.failed';
}
