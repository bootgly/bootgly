<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues;


use function max;


/**
 * Retry backoff policies.
 *
 * Given the upcoming attempt number and a base delay (seconds), each policy
 * computes how long a failed job waits before becoming due again:
 * - `Fixed`       — always `base`.
 * - `Linear`      — `base * attempt`.
 * - `Exponential` — `base * 2^(attempt - 1)`.
 */
enum Backoffs
{
   case Fixed;
   case Linear;
   case Exponential;

   /**
    * Delay in seconds before the given attempt becomes due.
    *
    * @param int $attempt Upcoming attempt number (1-based).
    * @param int $base Base delay in seconds.
    */
   public function delay (int $attempt, int $base): int
   {
      // :
      return match ($this) {
         Backoffs::Fixed       => $base,
         Backoffs::Linear      => $base * $attempt,
         Backoffs::Exponential => (int) ($base * (2 ** max(0, $attempt - 1))),
      };
   }
}
