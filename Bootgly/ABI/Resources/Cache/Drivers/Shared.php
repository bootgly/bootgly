<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Cache\Drivers;


use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function constant;
use function count;
use function crc32;
use function defined;
use function extension_loaded;
use function file;
use function function_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_readable;
use function is_string;
use function octdec;
use function posix_geteuid;
use function preg_split;
use function sem_acquire;
use function sem_get;
use function sem_release;
use function sem_remove;
use function shm_attach;
use function shm_detach;
use function shm_get_var;
use function shm_has_var;
use function shm_put_var;
use function shm_remove;
use function shm_remove_var;
use function time;
use function trim;
use RuntimeException;
use SysvSemaphore;
use SysvSharedMemory;
use Throwable;

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
 * crc32(key) selects a compact shared-memory slot, while the full key identifies
 * a record inside that slot. Non-colliding slots retain the single-record hot
 * path; a real collision promotes the slot to a full-key bucket so reads,
 * writes, counters, deletion, expiry and tag invalidation remain independent.
 * Reads hold the semaphore because the SysV variable table is mutated in-place.
 * The segment is fixed-size, so Redis remains the choice for unbounded caches.
 */
class Shared extends Driver
{
   private const int BUCKET_VERSION = 1;
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

      // ! Reads must hold the semaphore: a concurrent shm_put_var /
      //   shm_remove_var from another worker mutates the segment's variable
      //   table mid-read, making shm_get_var fail with corrupted data.
      sem_acquire($this->Semaphore);
      try {
         // ?
         if (shm_has_var($this->Segment, $id) === false) {
            return null;
         }

         $stored = shm_get_var($this->Segment, $id);
      }
      finally {
         sem_release($this->Semaphore);
      }

      // ? Missing or expired (lazy — purge() reclaims space)
      $record = $this->find($stored, $key);
      if ($record === null) {
         return null;
      }
      $expiry = $record['e'];
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
         $stored = $existed === true
            ? shm_get_var($this->Segment, $id)
            : null;
         $record = ['e' => $expiry, 'v' => $value, 't' => $tags];
         shm_put_var($this->Segment, $id, $this->write($stored, $key, $record));

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
            $stored = shm_get_var($this->Segment, $id);
            $updated = $this->erase($stored, $key);

            if ($updated === null) {
               shm_remove_var($this->Segment, $id);
               $this->untrack($id);
            }
            else {
               shm_put_var($this->Segment, $id, $updated);
            }
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

      // ! Locked read — see fetch()
      sem_acquire($this->Semaphore);
      try {
         // ?
         if (shm_has_var($this->Segment, $id) === false) {
            return false;
         }

         $stored = shm_get_var($this->Segment, $id);
      }
      finally {
         sem_release($this->Semaphore);
      }

      $record = $this->find($stored, $key);
      if ($record === null) {
         return false;
      }
      $expiry = $record['e'];

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
         $stored = $existed === true
            ? shm_get_var($this->Segment, $id)
            : null;
         $record = $this->find($stored, $key);

         if ($record !== null) {
            if (
               $record['e'] === 0
               || $record['e'] > $now
            ) {
               if (is_int($record['v']) === true) {
                  $base = $record['v'];
               }
               $expiry = $record['e'];
               $live = true;
            }
         }

         // @ TTL applies only when creating the counter
         $value = $base + $by;
         if ($TTL > 0 && $live === false) {
            $expiry = $now + $TTL;
         }

         $tags = $live === true && is_array($record['t'] ?? null)
            ? $record['t']
            : [];
         shm_put_var(
            $this->Segment,
            $id,
            $this->write($stored, $key, ['e' => $expiry, 'v' => $value, 't' => $tags])
         );
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

      // ! Locked read — see fetch()
      sem_acquire($this->Semaphore);
      try {
         // ?
         if (shm_has_var($this->Segment, $id) === false) {
            return -2;
         }

         $stored = shm_get_var($this->Segment, $id);
      }
      finally {
         sem_release($this->Semaphore);
      }

      $record = $this->find($stored, $key);
      if ($record === null) {
         return -2;
      }

      $expiry = $record['e'];
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
            $storedTags = shm_get_var($this->Segment, $tagId);
            $tagBuckets = null;
            if (
               is_array($storedTags) === true
               && ($storedTags['b'] ?? null) === self::BUCKET_VERSION
               && is_array($storedTags['t'] ?? null) === true
            ) {
               $tagBuckets = $storedTags['t'];
               $memberSet = $tagBuckets[$tag] ?? [];
               $members = is_array($memberSet) === true
                  ? array_keys($memberSet)
                  : [];
            }
            else {
               // @ Legacy tag records stored a plain list of value-slot ids.
               $members = is_array($storedTags) === true ? $storedTags : [];
            }

            foreach ($members as $member) {
               // ? Member ids are always integers
               if (
                  is_int($member) === false
                  || shm_has_var($this->Segment, $member) === false
               ) {
                  continue;
               }

               $stored = shm_get_var($this->Segment, $member);
               $records = $this->expand($stored);
               foreach ($records as $key => $record) {
                  $tags = $record['t'] ?? null;
                  // @ A record without tag metadata is a legacy single-value
                  //   entry referenced by the legacy member list.
                  if (is_array($tags) === false || in_array($tag, $tags, true) === true) {
                     unset($records[$key]);
                  }
               }

               $updated = $this->collapse($records);
               if ($updated === null) {
                  shm_remove_var($this->Segment, $member);
                  $this->untrack($member);
               }
               else {
                  shm_put_var($this->Segment, $member, $updated);
               }
            }

            if ($tagBuckets !== null) {
               unset($tagBuckets[$tag]);
               if ($tagBuckets === []) {
                  $this->untrack($tagId);
                  shm_remove_var($this->Segment, $tagId);
               }
               else {
                  shm_put_var($this->Segment, $tagId, [
                     'b' => self::BUCKET_VERSION,
                     't' => $tagBuckets,
                  ]);
               }
            }
            else {
               $this->untrack($tagId);
               shm_remove_var($this->Segment, $tagId);
            }
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

               // @ Tag metadata occupies its own band and has no expiry.
               if ($id >= self::TAG_BAND) {
                  continue;
               }

               $stored = shm_get_var($this->Segment, $id);
               $records = $this->expand($stored);
               $slotChanged = false;
               foreach ($records as $key => $record) {
                  $expiry = $record['e'];
                  if ($expiry !== 0 && $expiry <= $now) {
                     unset($records[$key]);
                     $count++;
                     $slotChanged = true;
                  }
               }

               if ($slotChanged === true) {
                  $updated = $this->collapse($records);
                  if ($updated === null) {
                     shm_remove_var($this->Segment, $id);
                     unset($bucket[$id]);
                  }
                  else {
                     shm_put_var($this->Segment, $id, $updated);
                  }
                  $changed = true;
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
    * Find one full-key record in either the compact or collision-bucket form.
    *
    * @return null|array{e:int,v:mixed,t?:array<int,string>}
    */
   private function find (mixed $stored, string $key): null|array
   {
      if (is_array($stored) === false) {
         return null;
      }

      if (($stored['k'] ?? null) === $key) {
         return $this->normalize($stored);
      }

      if (
         ($stored['b'] ?? null) !== self::BUCKET_VERSION
         || is_array($stored['r'] ?? null) === false
      ) {
         return null;
      }

      $record = $stored['r'][$key] ?? null;

      return is_array($record) === true
         ? $this->normalize($record)
         : null;
   }

   /**
    * Write one full-key record without disturbing colliding records.
    *
    * @param array{e:int,v:mixed,t?:array<int,string>} $record
    * @return array<string,mixed>
    */
   private function write (mixed $stored, string $key, array $record): array
   {
      $record = $this->normalize($record);
      if (is_array($stored) === false) {
         return $this->encode($key, $record);
      }

      $storedKey = $stored['k'] ?? null;
      if (is_string($storedKey) === true) {
         if ($storedKey === $key) {
            return $this->encode($key, $record);
         }

         return [
            'b' => self::BUCKET_VERSION,
            'r' => [
               $storedKey => $this->normalize($stored),
               $key => $record,
            ],
         ];
      }

      if (
         ($stored['b'] ?? null) === self::BUCKET_VERSION
         && is_array($stored['r'] ?? null) === true
      ) {
         $records = $stored['r'];
         $records[$key] = $record;

         return ['b' => self::BUCKET_VERSION, 'r' => $records];
      }

      return $this->encode($key, $record);
   }

   /**
    * Remove one full-key record and retain every colliding neighbor.
    *
    * @return null|array<string,mixed>
    */
   private function erase (mixed $stored, string $key): null|array
   {
      if (is_array($stored) === false) {
         return null;
      }

      if (is_string($stored['k'] ?? null) === true) {
         return $stored['k'] === $key ? null : $stored;
      }

      if (
         ($stored['b'] ?? null) !== self::BUCKET_VERSION
         || is_array($stored['r'] ?? null) === false
      ) {
         return $stored;
      }

      $records = $stored['r'];
      if (array_key_exists($key, $records) === false) {
         return $stored;
      }

      unset($records[$key]);

      return $this->collapse($records);
   }

   /**
    * Expand either storage form into records indexed by the complete key.
    *
    * @return array<int|string,array{e:int,v:mixed,t?:array<int,string>}>
    */
   private function expand (mixed $stored): array
   {
      if (is_array($stored) === false) {
         return [];
      }

      $key = $stored['k'] ?? null;
      if (is_string($key) === true) {
         return [$key => $this->normalize($stored)];
      }

      if (
         ($stored['b'] ?? null) !== self::BUCKET_VERSION
         || is_array($stored['r'] ?? null) === false
      ) {
         return [];
      }

      $records = [];
      foreach ($stored['r'] as $recordKey => $record) {
         if (is_array($record) === true) {
            $records[$recordKey] = $this->normalize($record);
         }
      }

      return $records;
   }

   /**
    * Collapse records back to the compact form when only one key remains.
    *
    * @param array<int|string,array{e:int,v:mixed,t?:array<int,string>}> $records
    * @return null|array<string,mixed>
    */
   private function collapse (array $records): null|array
   {
      if ($records === []) {
         return null;
      }

      if (count($records) === 1) {
         $key = array_key_first($records);
         $record = $records[$key];

         return $this->encode((string) $key, $record);
      }

      return ['b' => self::BUCKET_VERSION, 'r' => $records];
   }

   /**
    * Encode the non-colliding single-record hot path.
    *
    * @param array{e:int,v:mixed,t?:array<int,string>} $record
    * @return array<string,mixed>
    */
   private function encode (string $key, array $record): array
   {
      $record = $this->normalize($record);
      $stored = [
         'k' => $key,
         'e' => $record['e'],
         'v' => $record['v'],
      ];
      if (array_key_exists('t', $record) === true) {
         $stored['t'] = $record['t'];
      }

      return $stored;
   }

   /**
    * Normalize a record loaded from shared memory, including legacy entries.
    *
    * @param array<mixed,mixed> $record
    * @return array{e:int,v:mixed,t?:array<int,string>}
    */
   private function normalize (array $record): array
   {
      $expiry = $record['e'] ?? 0;
      $normalized = [
         'e' => is_int($expiry) === true ? $expiry : 0,
         'v' => $record['v'] ?? null,
      ];
      $storedTags = $record['t'] ?? null;
      if (is_array($storedTags) === true) {
         $tags = [];
         foreach ($storedTags as $tag) {
            if (is_string($tag) === true) {
               $tags[] = $tag;
            }
         }
         $normalized['t'] = $tags;
      }

      return $normalized;
   }

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
         : $this->derive();

      $Segment = shm_attach($key, $this->Config->size, $this->Config->permissions);
      if ($Segment === false) {
         throw new RuntimeException('Failed to attach the shared-memory segment.');
      }

      try {
         $this->guard($key, 'shm');
      }
      catch (Throwable $Throwable) {
         shm_detach($Segment);
         throw $Throwable;
      }

      $Semaphore = sem_get($key, 1, $this->Config->permissions, true);
      if ($Semaphore === false) {
         shm_detach($Segment);
         throw new RuntimeException('Failed to acquire the shared-memory semaphore.');
      }

      try {
         $this->guard($key, 'sem');
      }
      catch (Throwable $Throwable) {
         shm_detach($Segment);
         throw $Throwable;
      }

      $this->Segment = $Segment;
      $this->Semaphore = $Semaphore;
   }

   /** Derive a stable per-application key when no explicit segment is configured. */
   private function derive (): int
   {
      $scope = defined('BOOTGLY_WORKING_DIR')
         ? (string) constant('BOOTGLY_WORKING_DIR')
         : $this->Config->path;
      $key = crc32("bootgly.shared\0{$scope}\0" . __FILE__)
         & 0x7fffffff;

      return $key > 0 ? $key : 1;
   }

   /**
    * On Linux, reject a pre-existing SysV object whose owner or effective
    * permissions do not match this cache configuration.
    */
   private function guard (int $key, string $table): void
   {
      $path = "/proc/sysvipc/{$table}";
      if (is_readable($path) === false) {
         return;
      }

      $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (is_array($lines) === false) {
         throw new RuntimeException("Failed to inspect the SysV {$table} table.");
      }

      $ownerIndex = $table === 'shm' ? 7 : 4;
      $creatorIndex = $table === 'shm' ? 9 : 6;
      $EUID = function_exists('posix_geteuid') ? posix_geteuid() : null;

      foreach ($lines as $line) {
         $fields = preg_split('/\s+/', trim($line));
         if (
            is_array($fields) === false
            || isset($fields[0], $fields[2], $fields[$ownerIndex], $fields[$creatorIndex]) === false
            || (int) $fields[0] !== $key
         ) {
            continue;
         }

         $permissions = octdec($fields[2]);
         $owner = (int) $fields[$ownerIndex];
         $creator = (int) $fields[$creatorIndex];
         if (
            $permissions !== $this->Config->permissions
            || ($EUID !== null && ($owner !== $EUID || $creator !== $EUID))
         ) {
            throw new RuntimeException(
               "Refusing SysV {$table} key {$key}: unexpected owner or permissions."
            );
         }

         return;
      }

      throw new RuntimeException("Failed to locate SysV {$table} key {$key} after attach.");
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

      $existed = shm_has_var($this->Segment, $tagId);
      $stored = $existed === true
         ? shm_get_var($this->Segment, $tagId)
         : null;

      if (
         is_array($stored) === true
         && ($stored['b'] ?? null) === self::BUCKET_VERSION
         && is_array($stored['t'] ?? null) === true
      ) {
         $tagBuckets = $stored['t'];
      }
      else {
         $tagBuckets = [];
         // @ Upgrade a legacy plain member list under the tag that exposed it.
         if (is_array($stored) === true) {
            foreach ($stored as $member) {
               if (is_int($member) === true) {
                  $tagBuckets[$tag][$member] = true;
               }
            }
         }
      }

      $memberSet = $tagBuckets[$tag] ?? [];
      if (is_array($memberSet) === false) {
         $memberSet = [];
      }
      $memberSet[$id] = true;
      $tagBuckets[$tag] = $memberSet;

      shm_put_var($this->Segment, $tagId, [
         'b' => self::BUCKET_VERSION,
         't' => $tagBuckets,
      ]);
      if ($existed === false) {
         $this->track($tagId);
      }
   }
}
