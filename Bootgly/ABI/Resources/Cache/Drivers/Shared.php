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


use function array_keys;
use function crc32;
use function extension_loaded;
use function ftok;
use function in_array;
use function is_array;
use function is_int;
use function sem_acquire;
use function sem_get;
use function sem_release;
use function sem_remove;
use function shm_attach;
use function shm_get_var;
use function shm_has_var;
use function shm_put_var;
use function shm_remove;
use function shm_remove_var;
use function time;
use RuntimeException;
use SysvSemaphore;
use SysvSharedMemory;

use Bootgly\ABI\Resources\Cache\Driver;


/**
 * Shared-memory cache driver (per-host, cross-worker).
 *
 * Backed by a System V shared-memory segment (sysvshm) guarded by a System V
 * semaphore (sysvsem): every forked worker on the host sees the same data, so
 * this is the canonical shared backend for the multi-worker rate limiter.
 * increment() is atomic under the semaphore. A reserved index var enumerates
 * live keys for clear()/purge(); the index is touched only on key creation and
 * deletion, never on plain increments, keeping the hot path cheap.
 *
 * Values are keyed by crc32(key); the originating key is stored in-record and
 * verified on read so a hash collision degrades to a miss, never a wrong value.
 * Reads are lock-free (whole-var puts under lock keep each var self-consistent);
 * the segment is fixed-size, so Redis remains the choice for unbounded caches.
 */
class Shared extends Driver
{
   /**
    * Base key (just past the crc32 range) for the sharded live-key index.
    * The index is split across INDEX_BUCKETS vars keyed INDEX_BAND + (id % N),
    * so creating or deleting a key rewrites only one small bucket instead of
    * the whole key set — turning an O(N) hot path into O(N / INDEX_BUCKETS).
    */
   private const int INDEX_BAND = 4294967296;
   private const int INDEX_BUCKETS = 256;
   /**
    * Key band (outside the crc32 + index range) separating tag sets from values.
    */
   private const int TAG_BAND = 8589934592;

   // * Metadata
   private SysvSharedMemory $Segment;
   private SysvSemaphore $Semaphore;
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
      $this->attach();

      $id = crc32($key);
      // ?
      if (shm_has_var($this->Segment, $id) === false) {
         return null;
      }

      $record = shm_get_var($this->Segment, $id);
      // ? Missing, collided or expired (lazy — purge() reclaims space)
      if (is_array($record) === false || ($record['k'] ?? null) !== $key) {
         return null;
      }
      $expiry = $record['e'] ?? 0;
      if ($expiry !== 0 && $expiry <= $this->now) {
         return null;
      }

      // :
      return $record['v'] ?? null;
   }

   /**
    * @param array<int,string> $tags
    */
   public function store (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      $this->attach();

      $now = $this->now;
      $expiry = $TTL > 0 ? $now + $TTL : 0;
      $id = crc32($key);

      sem_acquire($this->Semaphore);
      try {
         $existed = shm_has_var($this->Segment, $id);
         shm_put_var($this->Segment, $id, ['k' => $key, 'e' => $expiry, 'v' => $value]);

         if ($existed === false) {
            $this->track($id);
         }

         foreach ($tags as $tag) {
            $this->bind($tag, $id);
         }
      }
      finally {
         sem_release($this->Semaphore);
      }

      return true;
   }

   public function delete (string $key): bool
   {
      $this->attach();

      $id = crc32($key);

      sem_acquire($this->Semaphore);
      try {
         if (shm_has_var($this->Segment, $id) === true) {
            shm_remove_var($this->Segment, $id);
            $this->untrack($id);
         }
      }
      finally {
         sem_release($this->Semaphore);
      }

      return true;
   }

   public function clear (): bool
   {
      $this->attach();

      sem_acquire($this->Semaphore);
      try {
         for ($b = 0; $b < self::INDEX_BUCKETS; $b++) {
            $bucketId = self::INDEX_BAND + $b;
            if (shm_has_var($this->Segment, $bucketId) === false) {
               continue;
            }

            $bucket = shm_get_var($this->Segment, $bucketId);
            if (is_array($bucket) === true) {
               foreach (array_keys($bucket) as $id) {
                  $id = (int) $id;
                  if (shm_has_var($this->Segment, $id) === true) {
                     shm_remove_var($this->Segment, $id);
                  }
               }
            }

            shm_remove_var($this->Segment, $bucketId);
         }
      }
      finally {
         sem_release($this->Semaphore);
      }

      return true;
   }

   public function check (string $key): bool
   {
      $this->attach();

      $id = crc32($key);
      // ?
      if (shm_has_var($this->Segment, $id) === false) {
         return false;
      }

      $record = shm_get_var($this->Segment, $id);
      if (is_array($record) === false || ($record['k'] ?? null) !== $key) {
         return false;
      }
      $expiry = $record['e'] ?? 0;

      // :
      return $expiry === 0 || $expiry > $this->now;
   }

   public function increment (string $key, int $by = 1, int $TTL = 0): int
   {
      $this->attach();

      $now = $this->now;
      $id = crc32($key);

      sem_acquire($this->Semaphore);
      try {
         $base = 0;
         $expiry = 0;
         $live = false;
         $existed = shm_has_var($this->Segment, $id);

         if ($existed === true) {
            $record = shm_get_var($this->Segment, $id);
            if (
               is_array($record) === true
               && ($record['k'] ?? null) === $key
               && (($record['e'] ?? 0) === 0 || ($record['e'] ?? 0) > $now)
            ) {
               if (is_int($record['v'] ?? null) === true) {
                  $base = $record['v'];
               }
               $expiry = $record['e'] ?? 0;
               $live = true;
            }
         }

         // @ TTL applies only when creating the counter
         $value = $base + $by;
         if ($TTL > 0 && $live === false) {
            $expiry = $now + $TTL;
         }

         shm_put_var($this->Segment, $id, ['k' => $key, 'e' => $expiry, 'v' => $value]);
         if ($existed === false) {
            $this->track($id);
         }
      }
      finally {
         sem_release($this->Semaphore);
      }

      return $value;
   }

   public function remain (string $key): int
   {
      $this->attach();

      $now = $this->now;
      $id = crc32($key);

      // ?
      if (shm_has_var($this->Segment, $id) === false) {
         return -2;
      }

      $record = shm_get_var($this->Segment, $id);
      if (is_array($record) === false || ($record['k'] ?? null) !== $key) {
         return -2;
      }

      $expiry = $record['e'] ?? 0;
      // ?: No expiry
      if ($expiry === 0) {
         return -1;
      }
      // ? Expired
      if ($expiry <= $now) {
         return -2;
      }

      // :
      return $expiry - $now;
   }

   public function invalidate (string $tag): bool
   {
      $this->attach();

      $tagId = self::TAG_BAND + crc32($tag);

      sem_acquire($this->Semaphore);
      try {
         if (shm_has_var($this->Segment, $tagId) === true) {
            $members = shm_get_var($this->Segment, $tagId);
            if (is_array($members) === true) {
               foreach ($members as $member) {
                  // ? Member ids are always integers
                  if (is_int($member) === false) {
                     continue;
                  }
                  if (shm_has_var($this->Segment, $member) === true) {
                     shm_remove_var($this->Segment, $member);
                  }
                  $this->untrack($member);
               }
            }

            $this->untrack($tagId);
            shm_remove_var($this->Segment, $tagId);
         }
      }
      finally {
         sem_release($this->Semaphore);
      }

      return true;
   }

   public function purge (): int
   {
      $this->attach();

      $now = $this->now;
      $count = 0;

      sem_acquire($this->Semaphore);
      try {
         for ($b = 0; $b < self::INDEX_BUCKETS; $b++) {
            $bucketId = self::INDEX_BAND + $b;
            if (shm_has_var($this->Segment, $bucketId) === false) {
               continue;
            }

            $bucket = shm_get_var($this->Segment, $bucketId);
            if (is_array($bucket) === false) {
               continue;
            }

            $changed = false;
            foreach (array_keys($bucket) as $id) {
               $id = (int) $id;

               if (shm_has_var($this->Segment, $id) === false) {
                  unset($bucket[$id]);
                  $changed = true;
                  continue;
               }

               $record = shm_get_var($this->Segment, $id);
               if (is_array($record) === true) {
                  $expiry = $record['e'] ?? 0;
                  if ($expiry !== 0 && $expiry <= $now) {
                     shm_remove_var($this->Segment, $id);
                     unset($bucket[$id]);
                     $count++;
                     $changed = true;
                  }
               }
            }

            if ($changed === true) {
               shm_put_var($this->Segment, $bucketId, $bucket);
            }
         }
      }
      finally {
         sem_release($this->Semaphore);
      }

      return $count;
   }

   /**
    * Remove the shared-memory segment and semaphore from the host.
    *
    * Frees the OS resources for the whole host (not just this worker); a later
    * operation re-attaches a fresh segment lazily.
    */
   public function destroy (): bool
   {
      // ?
      if (isset($this->Segment) === false) {
         return true;
      }

      shm_remove($this->Segment);
      sem_remove($this->Semaphore);
      unset($this->Segment, $this->Semaphore);

      return true;
   }

   // ---

   /**
    * Lazily attach the shared-memory segment and semaphore.
    */
   private function attach (): void
   {
      // ?: Already attached
      if (isset($this->Segment) === true) {
         return;
      }

      // ? Required extensions
      if (extension_loaded('sysvshm') === false || extension_loaded('sysvsem') === false) {
         throw new RuntimeException('The Shared-memory cache driver requires ext-sysvshm and ext-sysvsem.');
      }

      $key = $this->Config->segment !== 0
         ? $this->Config->segment
         : ftok(__FILE__, 't');

      $Segment = shm_attach($key, $this->Config->size, 0666);
      if ($Segment === false) {
         throw new RuntimeException('Failed to attach the shared-memory segment.');
      }

      $Semaphore = sem_get($key, 1, 0666, true);
      if ($Semaphore === false) {
         throw new RuntimeException('Failed to acquire the shared-memory semaphore.');
      }

      $this->Segment = $Segment;
      $this->Semaphore = $Semaphore;
   }

   /**
    * Add a var key to its live-key index bucket (caller holds the semaphore).
    */
   private function track (int $id): void
   {
      $bucketId = self::INDEX_BAND + ($id % self::INDEX_BUCKETS);

      $bucket = shm_has_var($this->Segment, $bucketId) === true
         ? shm_get_var($this->Segment, $bucketId)
         : [];
      if (is_array($bucket) === false) {
         $bucket = [];
      }

      $bucket[$id] = true;
      shm_put_var($this->Segment, $bucketId, $bucket);
   }

   /**
    * Remove a var key from its live-key index bucket (caller holds the semaphore).
    */
   private function untrack (int $id): void
   {
      $bucketId = self::INDEX_BAND + ($id % self::INDEX_BUCKETS);

      if (shm_has_var($this->Segment, $bucketId) === false) {
         return;
      }

      $bucket = shm_get_var($this->Segment, $bucketId);
      if (is_array($bucket) === false) {
         return;
      }

      unset($bucket[$id]);
      shm_put_var($this->Segment, $bucketId, $bucket);
   }

   /**
    * Add a value key to a tag's member set (caller holds the semaphore).
    */
   private function bind (string $tag, int $id): void
   {
      $tagId = self::TAG_BAND + crc32($tag);

      $members = shm_has_var($this->Segment, $tagId) === true
         ? shm_get_var($this->Segment, $tagId)
         : [];
      if (is_array($members) === false) {
         $members = [];
      }

      // ?: Already a member
      if (in_array($id, $members, true) === true) {
         return;
      }

      $members[] = $id;
      shm_put_var($this->Segment, $tagId, $members);
      $this->track($tagId);
   }
}
