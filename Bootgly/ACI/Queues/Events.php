<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues;


use Bootgly\ABI\Event;


/**
 * Queue domain events, dispatched through `Emitter::$Instance`.
 *
 * Each case is an event-identity singleton (keyed by `spl_object_id`):
 * - `Dispatch`  — a job was enqueued. Payload: `$queue` (string), `Job`.
 * - `Processed` — a job ran successfully. Payload: `Job`, `$durationMs` (float).
 * - `Failed`    — a job threw. Payload: `Job`, `Throwable`, `$willRetry` (bool).
 */
enum Events implements Event
{
   case Dispatch;
   case Processed;
   case Failed;
}
