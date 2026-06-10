<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache;


use Bootgly\ABI\Resources\Cache\Config;


/**
 * Cache driver contract.
 *
 * Concrete drivers (File, APCu, Shared-memory, Redis) implement one blocking
 * backend each. Keys arriving here are already namespaced by the Cache facade.
 */
abstract class Driver
{
   // * Config
   public Config $Config;


   public function __construct (Config $Config)
   {
      // * Config
      $this->Config = $Config;
   }

   /**
    * Read a value; null on miss or expiry.
    */
   abstract public function fetch (string $key): mixed;
   /**
    * Write a value with an optional TTL (seconds, 0 = forever) and tags.
    *
    * @param array<int,string> $tags
    */
   abstract public function store (string $key, mixed $value, int $TTL = 0, array $tags = []): bool;
   /**
    * Remove one key.
    */
   abstract public function delete (string $key): bool;
   /**
    * Flush every key owned by this driver.
    */
   abstract public function clear (): bool;
   /**
    * Whether a key exists and is not expired.
    */
   abstract public function check (string $key): bool;
   /**
    * Atomically increase an integer counter, creating it at 0 when absent.
    *
    * A positive $TTL sets the entry's expiry only when the counter is first
    * created; existing counters keep their expiry (fixed-window friendly,
    * matching Redis INCR + one-time EXPIRE).
    */
   abstract public function increment (string $key, int $by = 1, int $TTL = 0): int;
   /**
    * Remaining time-to-live in seconds.
    *
    * Mirrors Redis: -2 when the key is missing or expired, -1 when it exists
    * without expiry, otherwise the seconds left.
    */
   abstract public function remain (string $key): int;
   /**
    * Drop every key carrying the given tag.
    */
   abstract public function invalidate (string $tag): bool;
   /**
    * Evict expired entries; returns the number removed.
    */
   abstract public function purge (): int;

   /**
    * Atomically decrease an integer counter.
    */
   public function decrement (string $key, int $by = 1): int
   {
      return $this->increment($key, -$by);
   }
}
