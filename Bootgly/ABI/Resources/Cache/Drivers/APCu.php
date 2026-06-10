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


use const APC_ITER_KEY;
use function apcu_delete;
use function apcu_exists;
use function apcu_fetch;
use function apcu_inc;
use function apcu_store;
use function array_values;
use function extension_loaded;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_quote;
use function time;
use APCUIterator;
use RuntimeException;

use Bootgly\ABI\Resources\Cache\Driver;


/**
 * APCu cache driver.
 *
 * Process-local shared memory — fastest local backend, but each PHP worker has
 * its own APCu segment in CLI/forked deployments. Not suitable as the shared
 * cross-worker backend (use Shared-memory or Redis for that). TTL is native;
 * tag membership is tracked in a companion set entry.
 */
class APCu extends Driver
{
   public function fetch (string $key): mixed
   {
      $this->guard();

      $value = apcu_fetch($key, $success);

      // :
      return $success === true ? $value : null;
   }

   /**
    * @param array<int,string> $tags
    */
   public function store (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      $this->guard();

      // @ Store the value with native TTL (0 = forever)
      if (apcu_store($key, $value, $TTL) === false) {
         return false;
      }

      // @ Track tag membership
      foreach ($tags as $tag) {
         $this->tag($tag, $key, $TTL);
      }

      return true;
   }

   public function delete (string $key): bool
   {
      $this->guard();

      return apcu_delete($key) === true;
   }

   public function clear (): bool
   {
      $this->guard();

      $prefix = $this->Config->prefix;

      // @ Scope the flush to this cache's prefix (whole cache when prefix is empty)
      $pattern = '/^' . preg_quote($prefix, '/') . '/';
      apcu_delete(new APCUIterator($pattern, APC_ITER_KEY));

      return true;
   }

   public function check (string $key): bool
   {
      $this->guard();

      return apcu_exists($key) === true;
   }

   public function increment (string $key, int $by = 1, int $TTL = 0): int
   {
      $this->guard();

      // @ apcu_inc creates the counter at $by (with $TTL) when absent
      $value = apcu_inc($key, $by, $success, $TTL);

      // :
      return $success === true && $value !== false ? $value : 0;
   }

   public function remain (string $key): int
   {
      $this->guard();

      // ?
      if (apcu_exists($key) === false) {
         return -2;
      }

      $now = $this->Config->clock === null ? time() : (int) ($this->Config->clock)();
      $pattern = '/^' . preg_quote($key, '/') . '$/';

      foreach (new APCUIterator($pattern) as $info) {
         if (is_array($info) === false) {
            continue;
         }

         $TTL = $info['ttl'] ?? 0;
         $TTL = is_int($TTL) === true ? $TTL : 0;
         // ?: No expiry
         if ($TTL === 0) {
            return -1;
         }

         $created = $info['creation_time'] ?? 0;
         $created = is_int($created) === true ? $created : 0;
         $remaining = $created + $TTL - $now;

         // :
         return $remaining > 0 ? $remaining : -2;
      }

      // :
      return -2;
   }

   public function invalidate (string $tag): bool
   {
      $this->guard();

      $members = apcu_fetch($this->index($tag), $success);
      if ($success === true && is_array($members) === true) {
         foreach ($members as $member) {
            if (is_string($member) === true) {
               apcu_delete($member);
            }
         }
      }

      apcu_delete($this->index($tag));

      return true;
   }

   public function purge (): int
   {
      $this->guard();

      // APCu evicts expired entries natively; nothing to scan.
      return 0;
   }

   // ---

   /**
    * Ensure the APCu extension is available before any operation.
    */
   private function guard (): void
   {
      if (extension_loaded('apcu') === false) {
         throw new RuntimeException('The APCu cache driver requires ext-apcu.');
      }
   }

   /**
    * Build the companion set key for a tag.
    */
   private function index (string $tag): string
   {
      return "{$this->Config->prefix}@tag:{$tag}";
   }

   /**
    * Append a key to a tag's member set.
    */
   private function tag (string $tag, string $key, int $TTL): void
   {
      $index = $this->index($tag);

      $members = apcu_fetch($index, $success);
      if ($success !== true || is_array($members) === false) {
         $members = [];
      }

      // ?: Already a member
      if (in_array($key, $members, true) === true) {
         return;
      }

      $members[] = $key;
      apcu_store($index, array_values($members), $TTL);
   }
}
