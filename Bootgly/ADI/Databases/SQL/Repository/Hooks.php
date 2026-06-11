<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


use Bootgly\ABI\Event;


/**
 * ORM repository lifecycle hooks.
 *
 * Listeners stay **per-Repository-instance** (`Repository->listen()` over a
 * private list) — a different scope from the global `Emitter::$Instance` bus,
 * so the local emit/foreach is kept (no per-entity `Emission` allocation on the
 * ORM hot path). `Hooks` implements `Event` so the same identities can also be
 * dispatched globally when a cross-cutting observer needs them.
 */
enum Hooks implements Event
{
   case Deleted;
   case Deleting;
   case Hydrated;
   case Hydrating;
   case Saved;
   case Saving;
   case Selected;
   case Selecting;
}
