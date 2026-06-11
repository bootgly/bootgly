<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache;


use Bootgly\ABI\Event;


/**
 * Cache domain events, dispatched through `Emitter::$Instance`.
 *
 * Each case is an event-identity singleton (keyed by `spl_object_id`):
 * - `Hit`   — `fetch()` returned a stored value. Payload: `$key`, `$value`.
 * - `Miss`  — `fetch()` found nothing. Payload: `$key`.
 * - `Evict` — `delete()` removed a key. Payload: `$key`, `$deleted` (bool).
 */
enum Events implements Event
{
   case Hit;
   case Miss;
   case Evict;
}
