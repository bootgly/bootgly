<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Process;


use Bootgly\ABI\Event;


/**
 * Process / worker lifecycle events, dispatched through `Emitter::$Instance`.
 *
 * Shared across server platforms (WPI HTTP workers, future CLI workers) so a
 * worker dispatches its lifecycle without crossing layers — `ACI` events from
 * `WPI` are forward, and the emitter itself lives one layer below in `ABI`.
 *
 * - `Boot`     — a worker process started serving. Payload: `$index`.
 * - `Shutdown` — a process is stopping. Payload: `$level` (`'master'`/`'child'`).
 * - `Reload`   — reserved: no built-in server emits it. A reload replaces the
 *                workers, so each new one emits `Boot`.
 */
enum Events implements Event
{
   case Boot;
   case Shutdown;
   case Reload;
}
