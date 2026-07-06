<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use Bootgly\ABI\Event;


/**
 * Storage domain events, dispatched through `Emitter::$Instance`.
 *
 * Each case is an event-identity singleton (keyed by `spl_object_id`):
 * - `Written` — `write()` stored bytes. Payload: `$path`, `$written` (bool).
 * - `Read`    — `read()` was attempted. Payload: `$path`, `$found` (bool).
 * - `Deleted` — `delete()` ran. Payload: `$path`, `$deleted` (bool).
 */
enum Events implements Event
{
   case Written;
   case Read;
   case Deleted;
}
