<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT;


use function count;
use InvalidArgumentException;


/**
 * Ordered JWT key collection with `kid` resolution.
 */
class KeySet implements KeyResolver
{
   // * Config
   // ...

   // * Data
   /**
    * Keys indexed by `kid`.
    *
    * @var array<string,Key>
    */
   private array $Keys = [];
   /**
      * Single key without a `kid`, used for backwards-compatible JWTs.
      *
      * Key rotation must use explicit `kid` values. A no-`kid` key set remains
      * single-slot by design so verifiers never guess between defaults.
    */
   private null|Key $Default = null;

   // * Metadata
   // ...


   /**
    * Create a key collection.
    */
   public function __construct (Key ...$Keys)
   {
      foreach ($Keys as $Key) {
         $this->add($Key);
      }
   }

   /**
    * Add a key to the collection.
    */
   public function add (Key $Key): self
   {
      if ($Key->id === null) {
         if ($this->Default !== null) {
            throw new InvalidArgumentException('Duplicate default JWT key. Use kid for rotation.');
         }

         $this->Default = $Key;

         return $this;
      }

      if (isset($this->Keys[$Key->id])) {
         throw new InvalidArgumentException('Duplicate JWT key id.');
      }

      $this->Keys[$Key->id] = $Key;

      return $this;
   }

   /**
    * Get a key by id.
    */
   public function get (string $id): null|Key
   {
      return $this->Keys[$id] ?? null;
   }

   /**
    * Resolve the only safe key for a header `kid` and algorithm.
    */
   public function resolve (null|string $id, string $algorithm): null|Key
   {
      if ($id !== null) {
         if ($id === '') {
            return null;
         }

         $Key = $this->get($id);
         if ($Key === null || $Key->algorithm !== $algorithm) {
            return null;
         }

         return $Key;
      }

      $Matches = [];
      if ($this->Default !== null && $this->Default->algorithm === $algorithm) {
         $Matches[] = $this->Default;
      }

      foreach ($this->Keys as $Key) {
         if ($Key->algorithm === $algorithm) {
            $Matches[] = $Key;
         }
      }

      if (count($Matches) !== 1) {
         return null;
      }

      return $Matches[0];
   }

   /**
    * Key sets have no resolver failure state.
    */
   public function fail (): null|Failures
   {
      return null;
   }
}
