<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function serialize;
use WeakMap;


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
   // @ Columns a hydration actually carried, per entity. A row that omits a
   //   nullable or generated column leaves its property holding the class
   //   default, which is indistinguishable from a value the caller chose — so
   //   an UPDATE built from the whole map writes defaults over stored data.
   /** @var WeakMap<object,array<string,true>> */
   private WeakMap $hydrated;

   // * Metadata
   // ...



   public function __construct ()
   {
      $this->hydrated = new WeakMap();
   }
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
   /**
    * Record which columns one hydration carried for an entity.
    *
    * @param array<string,true> $columns
    */
   public function record (object $Entity, array $columns): void
   {
      $this->hydrated[$Entity] = ($this->hydrated[$Entity] ?? []) + $columns;
   }

   /**
    * Read the columns hydrations have carried for an entity.
    *
    * Null means this repository never hydrated it — the caller built it, so
    * every mapped column is the caller's to write.
    *
    * @return null|array<string,true>
    */
   public function carried (object $Entity): null|array
   {
      // :
      return $this->hydrated[$Entity] ?? null;
   }

   public function reset (): void
   {
      $this->entities = [];
      $this->hydrated = new WeakMap();
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
