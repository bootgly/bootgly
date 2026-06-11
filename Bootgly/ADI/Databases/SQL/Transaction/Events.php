<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
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
