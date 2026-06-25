<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache\Drivers;


use function in_array;
use function is_int;
use function time;

use Bootgly\ABI\Resources\Cache\Driver;


/**
 * In-process memory cache driver (per-process, no shared state).
 *
 * Backed by a plain PHP array living in the driver instance, so every entry is
 * a direct hash lookup with no serialization, no locks and no extension. This
 * is the fastest backend, but each PHP worker keeps its own copy: nothing is
 * shared across forked workers (use Shared-memory or Redis for that) and the
 * whole store dies with the process. Ideal as a single-process cache, a test
 * double, or the L1 tier in front of a slower shared backend.
 *
 * TTL is evaluated lazily against the configured clock; tag membership is
 * tracked in a companion map so invalidate() drops every key under a tag.
 */
class Memory extends Driver
{
   // * Metadata
   /**
    * Key => record map, where each record is ['e' => expiry, 'v' => value].
    *
    * @var array<string,array{e:int,v:mixed}>
    */
   private array $entries = [];
   /**
    * Tag => list of member keys, populated only when a store() carries tags.
    *
    * @var array<string,array<int,string>>
    */
   private array $tags = [];
   /**
    * Current Unix timestamp via the configured clock (time() when unset).
    */
   private int $now {
      get {
         $clock = $this->Config->clock;

         return $clock === null ? time() : (int) $clock();
      }
   }


   public function fetch (string $key): mixed
   {
      // ?
      if (isset($this->entries[$key]) === false) {
         return null;
      }

      $record = $this->entries[$key];
      $expiry = $record['e'];

      // ? Expired (lazy — purge() reclaims space)
      if ($expiry !== 0 && $expiry <= $this->now) {
         unset($this->entries[$key]);

         return null;
      }

      // :
      return $record['v'];
   }

   /**
    * @param array<int,string> $tags
    */
   public function store (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      $expiry = $TTL > 0 ? $this->now + $TTL : 0;

      $this->entries[$key] = ['e' => $expiry, 'v' => $value];

      foreach ($tags as $tag) {
         $this->bind($tag, $key);
      }

      return true;
   }

   public function delete (string $key): bool
   {
      unset($this->entries[$key]);

      return true;
   }

   public function clear (): bool
   {
      $this->entries = [];
      $this->tags = [];

      return true;
   }

   public function check (string $key): bool
   {
      // ?
      if (isset($this->entries[$key]) === false) {
         return false;
      }

      $expiry = $this->entries[$key]['e'];

      // ? Expired (lazy)
      if ($expiry !== 0 && $expiry <= $this->now) {
         unset($this->entries[$key]);

         return false;
      }

      return true;
   }

   public function increment (string $key, int $by = 1, int $TTL = 0): int
   {
      $now = $this->now;

      $base = 0;
      $expiry = 0;
      $live = false;

      if (isset($this->entries[$key]) === true) {
         $record = $this->entries[$key];
         $recordExpiry = $record['e'];

         // ? Live counter — keep its base and expiry
         if ($recordExpiry === 0 || $recordExpiry > $now) {
            if (is_int($record['v']) === true) {
               $base = $record['v'];
            }
            $expiry = $recordExpiry;
            $live = true;
         }
      }

      // @ TTL applies only when creating the counter
      $value = $base + $by;
      if ($TTL > 0 && $live === false) {
         $expiry = $now + $TTL;
      }

      $this->entries[$key] = ['e' => $expiry, 'v' => $value];

      // :
      return $value;
   }

   public function remain (string $key): int
   {
      // ?
      if (isset($this->entries[$key]) === false) {
         return -2;
      }

      $expiry = $this->entries[$key]['e'];

      // ?: No expiry
      if ($expiry === 0) {
         return -1;
      }
      // ? Expired
      if ($expiry <= $this->now) {
         return -2;
      }

      // :
      return $expiry - $this->now;
   }

   public function invalidate (string $tag): bool
   {
      // ?
      if (isset($this->tags[$tag]) === true) {
         foreach ($this->tags[$tag] as $key) {
            unset($this->entries[$key]);
         }

         unset($this->tags[$tag]);
      }

      return true;
   }

   public function purge (): int
   {
      $now = $this->now;
      $count = 0;

      foreach ($this->entries as $key => $record) {
         $expiry = $record['e'];

         if ($expiry !== 0 && $expiry <= $now) {
            unset($this->entries[$key]);
            $count++;
         }
      }

      // :
      return $count;
   }

   // ---

   /**
    * Add a key to a tag's member set.
    */
   private function bind (string $tag, string $key): void
   {
      if (isset($this->tags[$tag]) === false) {
         $this->tags[$tag] = [];
      }

      // ?: Already a member
      if (in_array($key, $this->tags[$tag], true) === true) {
         return;
      }

      $this->tags[$tag][] = $key;
   }
}
