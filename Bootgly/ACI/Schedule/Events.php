<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Schedule;


use Bootgly\ABI\Event;


/**
 * Scheduler domain events, dispatched through `Emitter::$Instance`.
 *
 * Each case is an event-identity singleton (keyed by `spl_object_id`):
 * - `Started`  — a job is about to run. Payload: `$id`, `Job`.
 * - `Finished` — a job completed successfully. Payload: `$id`, `$durationMs` (float).
 * - `Failed`   — a job threw. Payload: `$id`, `Throwable`.
 * - `Skipped`  — a run was skipped. Payload: `$id`, `$reason` ('overlap'|'catchup-skip').
 */
enum Events implements Event
{
   case Started;
   case Finished;
   case Failed;
   case Skipped;
}
