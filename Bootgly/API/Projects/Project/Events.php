<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Projects\Project;


use Bootgly\ABI\Event;


/**
 * Project lifecycle events, dispatched through `Emitter::$Instance`.
 *
 * - `Boot`     — the project was booted into the process. Payload: the `Project`.
 * - `Shutdown` — the booted project is being destroyed (process teardown / GC).
 *   Payload: the `Project`.
 */
enum Events implements Event
{
   case Boot;
   case Shutdown;
}
