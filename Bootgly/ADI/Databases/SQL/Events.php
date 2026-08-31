<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use Bootgly\ABI\Events\Emitter\Observing;


/**
 * SQL database events, dispatched through `Emitter::$Instance`.
 *
 * - `Connected` — a SQL connection finished authenticating (driver-level,
 *   SQL only). Payload: the `Connection`.
 * - `Executed`  — a SQL operation resolved successfully (one async query
 *   completed). Payload: the `Operation` (carries `->SQL` and `->Result`).
 * - `Slow`      — a resolved query exceeded `Operation::$slow` seconds.
 *   Off by default (`$slow = 0.0` → zero overhead). Payload: the `Operation`,
 *   the elapsed seconds (float).
 * - `Failed`    — a SQL operation failed: a driver error, a framework
 *   refusal, or an issuer-reported write that did not land. Payload: the
 *   `Operation` (carries `->SQL`, `->error` and `->code`).
 *
 * Observing: these events are emitted from driver read loops and teardown
 * paths, so their listeners are isolated — a listener Throwable is reported,
 * never propagated into the engine.
 */
enum Events implements Observing
{
   case Connected;
   case Executed;
   case Slow;
   case Failed;
}
