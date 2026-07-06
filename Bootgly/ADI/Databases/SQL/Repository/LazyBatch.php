<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


use Closure;
use RuntimeException;


/**
 * Shared lazy relation batch for one mapped-result hydration window.
 */
class LazyBatch
{
   // * Config
   public private(set) Closure $Loader;

   // * Data
   /** @var null|array<string,array<int,object>> */
   private null|array $groups = null;
   private bool $loading = false;

   // * Metadata
   // ...


   /**
    * @param Closure():array<string,array<int,object>> $Loader
    */
   public function __construct (Closure $Loader)
   {
      // * Config
      $this->Loader = $Loader;
   }

   /**
    * Fetch related entities for one parent key.
    *
    * @return array<int,object>
    */
   public function fetch (null|string $key): array
   {
      // @ Batch load.
      $groups = $this->load();

      // : Parent group.
      return $key === null ? [] : $groups[$key] ?? [];
   }

   /**
    * Load the shared relation batch once.
    *
    * @return array<string,array<int,object>>
    */
   public function load (): array
   {
      // ?: Cached groups.
      if ($this->groups !== null) {
         return $this->groups;
      }

      // ? Re-entrant lazy relation access.
      if ($this->loading) {
         throw new RuntimeException('ORM lazy relation is already loading.');
      }

      // @ Load groups.
      $this->loading = true;

      try {
         $groups = ($this->Loader)();
      }
      finally {
         $this->loading = false;
      }

      $this->groups = $groups;

      // : Loaded groups.
      return $groups;
   }

   /**
    * Reset loaded group cache.
    */
   public function reset (): void
   {
      $this->groups = null;
   }

   /**
    * Set already materialized relation groups.
    *
    * @param array<string,array<int,object>> $groups
    */
   public function set (array $groups): void
   {
      $this->groups = $groups;
   }
}