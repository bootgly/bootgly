<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events;


use function spl_object_id;
use Closure;
use UnitEnum;

use Bootgly\ABI\Event;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter\Listener;
use Bootgly\ABI\Events\Emitter\Listeners;


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
   /** @var array<int,Listeners> */
   protected array $Listeners = [];


   /**
    * Register one listener for an event.
    *
    * @param Event&UnitEnum $Event
    */
   public function listen (Event&UnitEnum $Event, Listener|Closure $Listener, int $priority = 0): self
   {
      ($this->Listeners[spl_object_id($Event)] ??= new Listeners)->add($Listener, $priority);

      // :
      return $this;
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

      foreach ($this->Listeners[$id] as $Listener) {
         $Listener instanceof Listener
            ? $Listener->handle($Emission)
            : $Listener($Emission);

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
