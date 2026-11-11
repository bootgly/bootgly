<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events\Emitter;


use function array_column;
use function array_key_last;
use ArrayIterator;
use Closure;
use IteratorAggregate;
use Traversable;

use Bootgly\ABI\Events\Emitter\Listener;


/**
 * Priority-ordered listener collection for a single event.
 *
 * Every registration has an id of its own, never reused: an emission in
 * flight iterates the registrations it started with and asks, by id, whether
 * each one is still here.
 *
 * The emitter owns its collections: it drops the one left empty from its
 * `$Listeners` map, which is what `check()` and the emit sites read. Register
 * and remove through `Emitter::listen()` and `Emitter::forget()` — a
 * collection emptied directly stays in the map, and its event keeps
 * answering as observed.
 *
 * @implements IteratorAggregate<int,Listener|Closure>
 */
class Listeners implements IteratorAggregate
{
   // * Data
   /**
    * Registrations by id, in dispatch order — descending priority, then
    * registration order: priority, listener, one-shot flag.
    *
    * Kept in order at registration, so an emission iterates it as it is.
    *
    * @var array<int,array{0:int,1:Listener|Closure,2:bool}>
    */
   public protected(set) array $entries = [];

   // * Metadata
   private int $sequence = 0;


   /**
    * Register one listener with an optional priority (higher runs first).
    *
    * A one-shot registration (`$once`) is the emitter's to drop before it
    * delivers to it.
    */
   public function add (Listener|Closure $Listener, int $priority = 0, bool $once = false): void
   {
      // !
      $id = $this->sequence++;
      $entry = [$priority, $Listener, $once];

      // ?: Not above the last one (the usual registration): it goes last
      $last = array_key_last($this->entries);
      if ($last === null || $priority <= $this->entries[$last][0]) {
         $this->entries[$id] = $entry;

         return;
      }

      // @@ Before the first registration of a lower priority
      $entries = [];
      $placed = false;
      foreach ($this->entries as $key => $registered) {
         if ($placed === false && $registered[0] < $priority) {
            $entries[$id] = $entry;
            $placed = true;
         }

         $entries[$key] = $registered;
      }

      $this->entries = $entries;
   }

   /**
    * Remove every registration of one listener.
    *
    * A `Listener` object matches by identity. A `Closure` matches by identity
    * or by equality, so a first-class callable (`$Object->handle(...)`)
    * removes the registration of an earlier one of the same method on the
    * same object; two closures written apart never match.
    *
    * @phpstan-impure
    *
    * @return bool Whether a registration was removed.
    */
   public function remove (Listener|Closure $Listener): bool
   {
      // !
      $loose = $Listener instanceof Closure;
      $removed = false;

      // @@
      foreach ($this->entries as $id => $entry) {
         $Registered = $entry[1];

         if (
            $Registered === $Listener
            || ($loose && $Registered instanceof Closure && $Registered == $Listener)
         ) {
            unset($this->entries[$id]);

            $removed = true;
         }
      }

      // :
      return $removed;
   }

   /**
    * Drop one registration by its id — its key in `$entries`.
    *
    * @phpstan-impure
    *
    * @return bool Whether the registration was still here.
    */
   public function drop (int $id): bool
   {
      // ?
      if (isSet($this->entries[$id]) === false) {
         return false;
      }

      // @
      unset($this->entries[$id]);

      // :
      return true;
   }

   /**
    * Iterate listeners in descending priority order, as a list.
    *
    * The iteration runs over the registrations of the moment it starts: one
    * added meanwhile is not in it, and one removed meanwhile still is. The
    * registration ids are the keys of `$entries`, not of this iteration.
    *
    * @return Traversable<int,Listener|Closure>
    */
   public function getIterator (): Traversable
   {
      // :
      return new ArrayIterator(array_column($this->entries, 1));
   }
}
