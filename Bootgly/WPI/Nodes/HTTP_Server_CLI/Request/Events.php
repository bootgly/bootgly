<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


use Bootgly\ABI\Event;


/**
 * HTTP request lifecycle events, dispatched through `Emitter::$Instance`.
 *
 * Multi-listener observability — distinct from the single `on()` request
 * handler slot (`HTTP_Server_CLI\Events::RequestReceived` → `SAPI::$Handler`),
 * which is left untouched.
 *
 * - `Received` — request fully decoded, about to be processed. Payload: `$Request`.
 * - `Handled`  — request processed, a `Response` is ready (sync path).
 *                Payload: `$Request`, `$Response`.
 */
enum Events implements Event
{
   case Received;
   case Handled;
}
