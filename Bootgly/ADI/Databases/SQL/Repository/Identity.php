<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function serialize;


/**
 * ORM identity map scoped to one repository context.
 */
class Identity
{
   // * Config
   // ...

   // * Data
   /** @var array<class-string,array<string,object>> */
   private array $entities = [];

   // * Metadata
   // ...


   /**
    * Fetch one already-hydrated entity by class and key.
    *
    * @param class-string $class
    */
   public function fetch (string $class, mixed $key): null|object
   {
      return $this->entities[$class][$this->index($key)] ?? null;
   }

   /**
    * Reset all tracked identities.
    */
   public function reset (): void
   {
      $this->entities = [];
   }

   /**
    * Store one hydrated entity by class and key.
    *
    * @param class-string $class
    */
   public function store (string $class, mixed $key, object $Entity): object
   {
      $this->entities[$class][$this->index($key)] = $Entity;

      return $Entity;
   }

   /**
    * Normalize identity keys for array storage.
    */
   private function index (mixed $key): string
   {
      if (is_bool($key)) {
         return $key ? 'true' : 'false';
      }

      if (is_float($key) || is_int($key) || is_string($key)) {
         return (string) $key;
      }

      return serialize($key);
   }
}
