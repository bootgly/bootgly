<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Projects\Project;


use Bootgly\ABI\Event;


/**
 * Project lifecycle events, dispatched through `Emitter::$Instance`.
 *
 * - `Boot` — the project was booted into the process. Payload: the `Project`.
 */
enum Events implements Event
{
   case Boot;
}
