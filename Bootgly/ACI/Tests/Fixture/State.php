<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Fixture;


use function array_key_exists;


/**
 * Mutable Fixture state bag with a resettable constructor seed.
 */
class State
{
   /**
    * Current values held by the Fixture.
    *
    * @var array<string, mixed>
    */
   public array $bag;

   /**
    * Initial values declared at construction; restored by reset().
    *
    * @var array<string, mixed>
    */
   private array $seed;


   /**
      * Create a state bag and snapshot its reset seed.
      *
    * @param array<string, mixed> $bag
    */
   public function __construct (array $bag = [])
   {
      $this->bag = $bag;
      $this->seed = $bag;
   }

   /**
    * Fetch one value from the state bag.
    */
   public function fetch (string $key, mixed $default = null): mixed
   {
      return array_key_exists($key, $this->bag)
         ? $this->bag[$key]
         : $default;
   }

   /**
    * Update one value in the state bag.
    */
   public function update (string $key, mixed $value): void
   {
      $this->bag[$key] = $value;
   }

   /**
    * Restore the state bag to its constructor seed.
    */
   public function reset (): void
   {
      $this->bag = $this->seed;
   }

   /**
    * Remove every value from the current state bag.
    */
   public function clear (): void
   {
      $this->bag = [];
   }
}
