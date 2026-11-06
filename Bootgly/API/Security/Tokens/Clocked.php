<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tokens;


use InvalidArgumentException;

use Bootgly\ABI\Clock;


/**
 * Frozen/wall-clock time source for deterministic token expiration checks.
 *
 * Token time is the frozen `$timestamp` when one is set (`freeze()`), else the
 * `$Clock`'s whole Unix seconds (`$time`).
 */
trait Clocked
{
   // * Data
   /**
    * The clock token time is read from while not frozen — the system Clock
    * unless one is assigned (such as the test double
    * `Bootgly\ACI\Tests\Doubles\Fake\Clock`).
    */
   public Clock $Clock {
      get => $this->Clock ??= new Clock;
   }
   /**
    * The frozen token time, in Unix seconds; null while it follows `$Clock`.
    */
   public private(set) null|int $timestamp = null;

   // * Metadata
   private int $time {
      get => $this->timestamp ?? $this->Clock->time;
   }


   /**
    * Freeze token time for deterministic checks.
    */
   public function freeze (int $timestamp): self
   {
      // ?
      if ($timestamp < 0) {
         throw new InvalidArgumentException('Token timestamp must not be negative.');
      }

      $this->timestamp = $timestamp;

      return $this;
   }

   /**
    * Resume token time on `$Clock`.
    */
   public function resume (): self
   {
      $this->timestamp = null;

      return $this;
   }
}
