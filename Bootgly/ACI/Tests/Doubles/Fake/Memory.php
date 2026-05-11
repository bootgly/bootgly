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


use function array_key_exists;

use Bootgly\ACI\Tests\Doubles\Fake as DoubleFake;


/**
 * In-memory key-value fake matching the HTTP session access shape.
 */
final class Memory extends DoubleFake
{
   // * Data
   /**
    * Stored values keyed by name.
    *
    * @var array<string,mixed>
    */
   private array $data = [];


   /**
    * Determine if a non-null value exists for a key.
    */
   public function check (string $name): bool
   {
      return array_key_exists($name, $this->data);
   }

   /**
    * Get one value by key, returning the default when absent or null.
    */
   public function get (string $name, mixed $default = null): mixed
   {
      return $this->data[$name] ?? $default;
   }

   /**
    * Store one value by key.
    */
   public function set (string $name, mixed $value): void
   {
      $this->data[$name] = $value;
   }

   /**
    * Delete one value by key.
    */
   public function delete (string $name): void
   {
      unset($this->data[$name]);
   }

   /**
    * List every stored value.
    *
    * @return array<string,mixed>
    */
   public function list (): array
   {
      return $this->data;
   }

   /**
    * Remove every stored value.
    */
   public function flush (): void
   {
      $this->data = [];
   }

   /**
    * Reset memory to an empty state.
    */
   public function reset (): static
   {
      $this->flush();

      return $this;
   }
}
