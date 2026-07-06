<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema\Migration;


use Bootgly\ABI\Event;


/**
 * SQL schema migration events, dispatched through `Emitter::$Instance`.
 *
 * Fired by the migration runner as each migration is applied/reverted.
 * Payload: the `Migration`, the batch number (int).
 *
 * - `Up`   — a migration was applied.
 * - `Down` — a migration was reverted.
 */
enum Events implements Event
{
   case Up;
   case Down;
}
