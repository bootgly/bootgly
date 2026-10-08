<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;


/**
 * Shares of the worker memory budget (`TCP_Server_CLI::$maxWorkerPendingBytes`),
 * named by how long their bytes live — never by the protocol that holds them.
 *
 * Inbound holds stay within half of the budget and Resident holds within a
 * quarter, so Transport always keeps at least a quarter: no inbound flood and
 * no cache can starve the output that drains the worker.
 */
enum Shares : int
{
   /** Bytes on their way out or between reads: pending output, receive carry, protocol state. Bound by the budget alone. */
   case Transport = 0;
   /** Unfinished inbound messages: request bodies, WebSocket carry and reassembly. At most half the budget. */
   case Inbound = 1;
   /** Bytes kept for reuse across requests: the route cache. At most a quarter of the budget. */
   case Resident = 2;
}
