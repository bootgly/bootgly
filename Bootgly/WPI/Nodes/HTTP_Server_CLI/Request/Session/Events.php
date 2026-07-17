<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


use Bootgly\ABI\Event;


/**
 * Session lifecycle events, dispatched through `Emitter::$Instance`.
 *
 * - `Start`      — a session was established for the request. Payload: `$id`.
 * - `Regenerate` — the session id was rotated. Payload: `$oldId`, `$newId`, `$Session`.
 * - `Destroy`    — the session was destroyed (emptied on save). Payload: `$id`.
 */
enum Events implements Event
{
   case Start;
   case Regenerate;
   case Destroy;
}
