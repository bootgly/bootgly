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


use RuntimeException;


/**
 * Lazy-loaded reference for singular ORM relations.
 *
 * Access contract for an empty (missing) singular relation:
 * - `fetch()` returns `null` — the null-safe way to read an optional relation.
 * - `__get`, `__set`, `__call` throw `RuntimeException` — proxying assumes a
 *   present related entity, so guard with `fetch()`/`empty` first.
 * - `__isset` returns `false` (never throws), so `isset($Parent->relation->prop)`
 *   is safe on an empty reference.
 */
class LazyReference
{
   // * Config
   public private(set) LazyBatch $Batch;
   public private(set) null|string $key;

   // * Data
   private bool $loaded = false;
   private null|object $Entity = null;

   // # Views
   public bool $empty {
      get => $this->fetch() === null;
   }

   // * Metadata
   // ...


   public function __construct (LazyBatch $Batch, null|string $key)
   {
      // * Config
      $this->Batch = $Batch;
      $this->key = $key;
   }

   /**
    * Proxy one method call to the loaded related entity.
    *
    * @param array<int,mixed> $arguments
    */
   public function __call (string $method, array $arguments): mixed
   {
      // @ Related entity.
      $Entity = $this->fetch();

      // ? Missing singular relation.
      if ($Entity === null) {
         throw new RuntimeException('ORM lazy reference is empty.');
      }

      // : Proxied call.
      return $Entity->{$method}(...$arguments);
   }

   /**
    * Proxy one property read to the loaded related entity.
    */
   public function __get (string $property): mixed
   {
      // @ Related entity.
      $Entity = $this->fetch();

      // ? Missing singular relation.
      if ($Entity === null) {
         throw new RuntimeException('ORM lazy reference is empty.');
      }

      // : Proxied property value.
      return $Entity->{$property};
   }

   /**
    * Proxy one property existence check to the loaded related entity.
    */
   public function __isset (string $property): bool
   {
      $Entity = $this->fetch();

      return $Entity !== null && isset($Entity->{$property});
   }

   /**
    * Proxy one property write to the loaded related entity.
    */
   public function __set (string $property, mixed $value): void
   {
      // @ Related entity.
      $Entity = $this->fetch();

      // ? Missing singular relation.
      if ($Entity === null) {
         throw new RuntimeException('ORM lazy reference is empty.');
      }

      // @ Proxied property write.
      $Entity->{$property} = $value;
   }

   /**
    * Fetch the related entity or null.
    */
   public function fetch (): null|object
   {
      // ?: Cached entity.
      if ($this->loaded) {
         return $this->Entity;
      }

      // @ Parent group load.
      $entities = $this->Batch->fetch($this->key);
      $this->Entity = $entities[0] ?? null;
      $this->loaded = true;

      // : Related entity.
      return $this->Entity;
   }

   /**
    * Reset loaded entity cache.
    */
   public function reset (): void
   {
      $this->loaded = false;
      $this->Entity = null;
   }

   /**
    * Set one already materialized related entity.
    */
   public function set (null|object $Entity): void
   {
      $this->Entity = $Entity;
      $this->loaded = true;
   }
}