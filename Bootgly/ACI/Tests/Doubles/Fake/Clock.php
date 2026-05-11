<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Doubles\Fake;


use Bootgly\ACI\Tests\Doubles\Fake as DoubleFake;


/**
 * Deterministic clock fake for time-sensitive tests.
 */
final class Clock extends DoubleFake
{
   // * Config
   /**
    * Initial timestamp restored by reset().
    */
   public private(set) float $initial;

   // * Data
   /**
    * Current fake timestamp.
    */
   public private(set) float $now;


   /**
    * Create a deterministic clock at the given timestamp.
    */
   public function __construct (int|float $at = 0)
   {
      $this->initial = (float) $at;
      $this->now = $this->initial;
   }

   /**
    * Move the fake clock forward by the given seconds.
    */
   public function advance (int|float $seconds): void
   {
      $this->now += (float) $seconds;
   }

   /**
    * Set the fake clock to an exact timestamp.
    */
   public function freeze (int|float $at): void
   {
      $this->now = (float) $at;
   }

   /**
    * Reset the fake clock to its initial timestamp.
    */
   public function reset (): static
   {
      $this->now = $this->initial;

      return $this;
   }
}
