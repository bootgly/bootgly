<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


use Bootgly\ABI\Event;


/**
 * Session lifecycle events, dispatched through `Emitter::$Instance`.
 *
 * - `Start`      — a session was established for the request. Payload: `$id`.
 * - `Regenerate` — the session id was rotated. Payload: `$oldId`, `$newId`.
 * - `Destroy`    — the session was destroyed (emptied on save). Payload: `$id`.
 */
enum Events implements Event
{
   case Start;
   case Regenerate;
   case Destroy;
}
