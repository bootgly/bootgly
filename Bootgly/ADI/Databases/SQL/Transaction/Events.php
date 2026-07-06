<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Transaction;


use Bootgly\ABI\Event;


/**
 * SQL transaction lifecycle events, dispatched through `Emitter::$Instance`.
 *
 * Fired when the corresponding statement operation is issued (the operation
 * itself completes asynchronously). Payload: the `Transaction`.
 *
 * - `Begin`    — `begin()` opened a transaction (top-level).
 * - `Commit`   — `commit()` committed the transaction.
 * - `Rollback` — `rollback()` rolled the transaction back.
 */
enum Events implements Event
{
   case Begin;
   case Commit;
   case Rollback;
}
