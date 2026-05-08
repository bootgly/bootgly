<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use Random\Engine\Mt19937;
use Random\Randomizer;


/**
 * Abstract data faker — deterministic when seeded.
 *
 * Subclasses override generate() to return a value of their domain
 * (Email, UUID, Integer, ...). The trait Fakers exposes a single
 * fake($kind, $seed) entry-point for tests.
 */
abstract class Faker
{
   /**
    * Randomizer used by concrete fakers to generate deterministic values.
    */
   public private(set) Randomizer $Randomizer;

   // * Config
   /**
    * The seed used to initialize the Randomizer, if any. May be used by
    * tests to correlate Faker output with Coverage reports.
    */
   public private(set) null|int $seed = null;


   /**
    * Create a Faker, optionally seeded for deterministic output.
    */
   public function __construct (null|int $seed = null)
   {
      $this->seed = $seed;
      $this->Randomizer = $seed === null
         ? new Randomizer()
         : new Randomizer(new Mt19937($seed));
   }

   /**
    * Produce one fake value.
    */
   abstract public function generate (): mixed;
}
