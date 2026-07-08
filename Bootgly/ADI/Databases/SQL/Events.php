<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use Bootgly\ABI\Event;


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
 */
enum Events implements Event
{
   case Connected;
   case Executed;
   case Slow;
}
