<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use Bootgly\ABI\Event;


/**
 * SQL query lifecycle events, dispatched through `Emitter::$Instance`.
 *
 * - `Executed` — a SQL operation resolved successfully (one async query
 *   completed). Payload: the `Operation` (carries `->sql` and `->Result`).
 */
enum Events implements Event
{
   case Executed;
}
