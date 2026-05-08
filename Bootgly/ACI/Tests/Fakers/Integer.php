<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Fakers;


use const PHP_INT_MAX;

use Bootgly\ACI\Tests\Faker;


/**
 * Integer faker with configurable inclusive bounds.
 */
final class Integer extends Faker
{
   /**
    * Minimum generated integer value.
    */
   public int $min = 0;
   /**
    * Maximum generated integer value.
    */
   public int $max = PHP_INT_MAX;


   /**
    * Generate one fake integer.
    */
   public function generate (): int
   {
      return $this->Randomizer->getInt($this->min, $this->max);
   }
}
