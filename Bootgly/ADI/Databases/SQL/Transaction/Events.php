<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
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
 * - `Begin`    — `$Transaction->begin()` was called on the `Transaction`
 *                to open a savepoint or open the transaction again; it is
 *                emitted before the statement is issued, so the savepoint
 *                may still be refused (an operation still active, a
 *                transaction no longer active). The `begin()` of the
 *                database, which creates the `Transaction`, emits none.
 * - `Commit`   — `commit()` committed the transaction.
 * - `Rollback` — `rollback()` rolled the transaction back.
 */
enum Events implements Event
{
   case Begin;
   case Commit;
   case Rollback;
}
