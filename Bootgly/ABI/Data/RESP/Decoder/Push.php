<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\RESP\Decoder;


/**
 * One RESP3 push frame (`>`), kept apart from the replies it arrives among.
 *
 * The codec does not decide what a push means: it preserves the type it
 * already parses, in stream order, so the reader can tell an out-of-band
 * frame from the answer to the command at the head of its queue. That
 * distinction cannot be made here — under RESP3 the confirmation of
 * `SUBSCRIBE` is itself a push frame and is a reply, while an `invalidate`
 * arriving between two commands answers neither of them.
 */
class Push
{
   // * Config
   /** @var array<int,mixed> The frame's decoded items, in order. */
   public private(set) array $items;


   /**
    * @param array<int,mixed> $items
    */
   public function __construct (array $items)
   {
      // * Config
      $this->items = $items;
   }
}
