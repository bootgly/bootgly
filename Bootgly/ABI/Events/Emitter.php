<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events;


use function spl_object_id;
use Closure;
use Throwable;
use UnitEnum;

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter\Listener;
use Bootgly\ABI\Events\Emitter\Listeners;
use Bootgly\ABI\Events\Emitter\Observing;


/**
 * Canonical synchronous event bus.
 *
 * Events are enum cases implementing Event; listeners are registered per event
 * and dispatched in priority order. Each emit() builds one Emission carrying
 * the payload and propagation state.
 */
final class Emitter
{
   // * Data
   public static self $Instance;

   // * Metadata
   // Readable directly by hot emit sites — isSet($Emitter->Listeners[$id])
   // skips the check() call frame + intersection-type check per request.
   // An event is a key only while it has a listener: removing the last one
   // (forget(), or a one-shot delivered) removes the key.
   /** @var array<int,Listeners> */
   public protected(set) array $Listeners = [];


   /**
    * Register one listener for an event.
    *
    * Higher `$priority` runs first; registration order breaks ties. With
    * `$once` the listener is one-shot: it is removed right before its first
    * delivery, so it runs at most once — also when it throws, or emits the
    * same event again. A one-shot listener that an emission never reaches
    * (propagation stopped above it) stays registered.
    *
    * @param Event&UnitEnum $Event
    */
   public function listen (
      Event&UnitEnum $Event,
      Listener|Closure $Listener,
      int $priority = 0,
      bool $once = false
   ): self
   {
      ($this->Listeners[spl_object_id($Event)] ??= new Listeners)->add($Listener, $priority, $once);

      // :
      return $this;
   }

   /**
    * Remove a listener from an event — every registration of it.
    *
    * A `Listener` object matches by identity; a `Closure` by identity or by
    * equality (two first-class callables of one method on one object). Once
    * it returns, the listener is not delivered to again, not even by an
    * emission already in flight; the other listeners of that emission run
    * as if it had never been registered. Removing the last listener of an
    * event makes `check()` answer false again.
    *
    * @param Event&UnitEnum $Event
    *
    * @return bool Whether a registration was removed.
    */
   public function forget (Event&UnitEnum $Event, Listener|Closure $Listener): bool
   {
      $id = spl_object_id($Event);

      // ? Nobody listens to the event
      if (isSet($this->Listeners[$id]) === false) {
         return false;
      }

      // @
      $Listeners = $this->Listeners[$id];
      $removed = $Listeners->remove($Listener);

      // ? Last listener gone: the event is unobserved again
      if ($Listeners->entries === []) {
         unset($this->Listeners[$id]);
      }

      // :
      return $removed;
   }

   /**
    * Check whether an event has at least one registered listener.
    *
    * Lets a hot emit site skip building the payload (and the Emission) when
    * nobody is listening, preserving zero allocation at the call site:
    * `$Emitter->check($Event) && $Emitter->emit($Event, ...$payload)`.
    *
    * @param Event&UnitEnum $Event
    */
   public function check (Event&UnitEnum $Event): bool
   {
      // :
      return isSet($this->Listeners[spl_object_id($Event)]);
   }

   /**
    * Emit an event synchronously to its listeners in priority order.
    *
    * Returns null when the event has no listeners (zero-allocation path).
    * The emission delivers to the listeners registered when it starts, minus
    * the ones removed since: a listener registered by a handler waits for
    * the next emission, one removed by a handler is not delivered to.
    *
    * @param Event&UnitEnum $Event
    */
   public function emit (Event&UnitEnum $Event, mixed ...$payload): null|Emission
   {
      $id = spl_object_id($Event);

      // ? No listeners: skip Emission allocation entirely
      if (isSet($this->Listeners[$id]) === false) {
         return null;
      }

      // @
      $Emission = new Emission($Event, $payload);

      // ! The event declares its listener contract. Observing events isolate
      //   each listener: diagnostics observe, they never steer — a broken one
      //   must not blind later listeners, abort delivery, or unwind into the
      //   emitting engine path (an exception escaping into a driver teardown
      //   loop leaves a dead connection counted as busy forever). Every other
      //   event keeps the steering contract: a listener Throwable propagates
      //   to the emitter, which may treat it as control flow — a refusal gate
      //   before routing, a bounded error boundary after it.
      $observing = $Event instanceof Observing;

      // ! The registrations as they are now: listen() and forget() inside a
      //   handler write another array, never the one being iterated
      $Listeners = $this->Listeners[$id];

      foreach ($Listeners->entries as $key => $entry) {
         // ? Removed since this emission started — by forget(), or a
         //   one-shot listener a nested emission already delivered to.
         //   (Static analysis cannot see a handler of an earlier pass
         //   writing the collection.)
         if (isSet($Listeners->entries[$key]) === false) { // @phpstan-ignore isset.offset
            continue;
         }

         // ? One-shot: removed BEFORE it runs, so it runs at most once even
         //   when it throws or emits this event again. A collection emptied
         //   here leaves the map and never fills again (listen() starts a
         //   new one), so the key removed is always its own.
         if ($entry[2]) {
            $Listeners->drop($key);

            if ($Listeners->entries === []) {
               unset($this->Listeners[$id]);
            }
         }

         $Listener = $entry[1];

         if ($observing) {
            try {
               $Listener instanceof Listener
                  ? $Listener->handle($Emission)
                  : $Listener($Emission);
            }
            catch (Throwable $Throwable) {
               // ? Contained, never silent — reported out-of-band (no-op
               //   without an attached reporter)
               Throwables::notify($Throwable, [
                  'interface' => 'ABI',
                  'phase' => 'Emitter',
                  'event' => $Event->name,
               ]);
            }
         }
         else {
            $Listener instanceof Listener
               ? $Listener->handle($Emission)
               : $Listener($Emission);
         }

         // ? Propagation halted by a listener
         if ($Emission->stopped) {
            break;
         }
      }

      // :
      return $Emission;
   }
}

// @ Boot
Emitter::$Instance = new Emitter;
