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


use Bootgly\ABI\Event;


/**
 * One in-flight event dispatch.
 *
 * Carries the dispatched event (an enum case) and its immutable payload, and
 * holds the propagation state so a listener can halt remaining listeners.
 */
final class Emission
{
   // * Config
   public private(set) Event $Event;
   /** @var array<mixed> */
   public private(set) array $payload;

   // * Data
   public private(set) bool $stopped = false;

   // * Metadata
   // ...


   /**
    * @param array<mixed> $payload
    */
   public function __construct (Event $Event, array $payload)
   {
      // * Config
      $this->Event = $Event;
      $this->payload = $payload;
   }

   /**
    * Stop event propagation — no further listeners run for this emit.
    */
   public function stop (): void
   {
      $this->stopped = true;
   }
}
