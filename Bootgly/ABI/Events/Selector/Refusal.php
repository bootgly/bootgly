<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events\Selector;


use RuntimeException;


/**
 * A Selector refused a stream (`watch()`) or a wait (`wait()`).
 *
 * The Selector never answers a broken set with a silent hang or a busy spin:
 * it names the cause here instead.
 */
final class Refusal extends RuntimeException
{
   // * Config
   /**
    * Why the Selector refused.
    */
   public private(set) Reasons $Reason;
   /**
    * Ids of the streams the refusal is about (`(int) $stream`, the key of the
    * Selector's sets): the refused stream of a `watch()`, the streams a
    * `wait()` dropped because they were closed. Empty when no stream is.
    *
    * @var array<int>
    */
   public private(set) array $streams;


   /**
    * @param Reasons $Reason Why the Selector refused.
    * @param string $message What happened, in words.
    * @param array<int> $streams Ids of the streams concerned.
    */
   public function __construct (Reasons $Reason, string $message, array $streams = [])
   {
      parent::__construct($message);

      // * Config
      $this->Reason = $Reason;
      $this->streams = $streams;
   }
}
