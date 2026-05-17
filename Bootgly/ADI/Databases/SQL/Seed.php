<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use Bootgly\ACI\Fakers;


/**
 * SQL seeding context backed by the neutral ACI faker stack.
 */
class Seed
{
   use Fakers {
      fake as private faker;
   }


   /**
    * Generate one fake value by concrete faker kind.
    */
   public function fake (string $kind, null|int $seed = null): mixed
   {
      return $this->faker($kind, $seed);
   }
}
