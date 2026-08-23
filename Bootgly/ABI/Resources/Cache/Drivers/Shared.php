<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
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
use function serialize;
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
   /** Internal RuntimeException code identifying fixed-segment capacity. */
   private const int CAPACITY_ERROR = 1;
   /**
    * Variable id carrying the record-format marker. Sits above every band the
    * driver derives from a key hash, so it can never collide with a record.
    */
   private const int FORMAT_ID = 12884901888;
   /**
    * Current stored-record format. Bump it whenever the representation changes:
    * a segment carrying anything else is discarded when it is attached.
    */
   private const int FORMAT = 2;

   // * Metadata
   /** @var array<int,int> Last automatic reclaim tick per segment in this worker. */
   private static array $reclaimed = [];
   private SysvSharedMemory $Segment;
   private SysvSemaphore $Semaphore;
   private int $segment;
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

         $stored = $this->load($id);
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
      return $this->persist($key, $value, $TTL, $tags);
   }

   /** @param array<int,string> $tags */
   public function create (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      return $this->persist($key, $value, $TTL, $tags, true);
   }

   /** @param array<int,string> $tags */
   public function swap (
      string $key,
      mixed $expected,
      mixed $value,
      int $TTL = 0,
      array $tags = [],
   ): bool
   {
      return $this->persist($key, $value, $TTL, $tags, false, $expected);
   }

   /**
    * @param array<int,string> $tags
    */
   private function persist (
      string $key,
      mixed $value,
      int $TTL,
      array $tags,
      null|bool $create = null,
      mixed $expected = null,
   ): bool
   {
      $this->attach();

      $id = crc32($key);

      if (sem_acquire($this->Semaphore) === false) {
         throw new RuntimeException('Failed to lock the shared-memory semaphore.');
      }
      try {
         $now = $this->now;
         $expiry = $TTL > 0 ? $now + $TTL : 0;
         $existed = shm_has_var($this->Segment, $id);
         $stored = $existed === true
            ? $this->load($id)
            : null;
         $current = $this->find($stored, $key);
         $live = $current !== null
            && ($current['e'] === 0 || $current['e'] > $now);
         if (
            ($create === true && $live)
            || (
               $create === false
               && ($live === false || $current['v'] !== $expected)
            )
         ) {
            return false;
         }

         $record = ['e' => $expiry, 'v' => $value, 't' => $tags];
         $this->put($id, $this->write($stored, $key, $record));

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

   public function evict (string $key, mixed $expected): bool
   {
      $this->attach();

      $id = crc32($key);

      if (sem_acquire($this->Semaphore) === false) {
         throw new RuntimeException('Failed to lock the shared-memory semaphore.');
      }
      try {
         $now = $this->now;
         if (shm_has_var($this->Segment, $id) === false) {
            return false;
         }

         $stored = $this->load($id);
         $record = $this->find($stored, $key);
         if (
            $record === null
            || ($record['e'] !== 0 && $record['e'] <= $now)
            || $record['v'] !== $expected
         ) {
            return false;
         }

         $updated = $this->erase($stored, $key);
         if ($updated === null) {
            $this->drop($id);
            $this->untrack($id);
         }
         else {
            $this->put($id, $updated);
         }
      }
      finally {
         sem_release($this->Semaphore);
      }

      return true;
   }

   public function renew (string $key, int $TTL = 0): bool
   {
      $this->attach();

      $id = crc32($key);

      if (sem_acquire($this->Semaphore) === false) {
         throw new RuntimeException('Failed to lock the shared-memory semaphore.');
      }
      try {
         $now = $this->now;
         if (shm_has_var($this->Segment, $id) === false) {
            return false;
         }

         $stored = $this->load($id);
         $record = $this->find($stored, $key);
         if (
            $record === null
            || ($record['e'] !== 0 && $record['e'] <= $now)
         ) {
            return false;
         }

         $record['e'] = $TTL > 0 ? $now + $TTL : 0;
         $this->put($id, $this->write($stored, $key, $record));
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
            $stored = $this->load($id);
            $updated = $this->erase($stored, $key);

            if ($updated === null) {
               $this->drop($id);
               $this->untrack($id);
            }
            else {
               $this->put($id, $updated);
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

            $bucket = $this->load($bucketId);
            if (is_array($bucket) === true) {
               foreach (array_keys($bucket) as $id) {
                  $id = (int) $id;
                  if (shm_has_var($this->Segment, $id) === true) {
                     $this->drop($id);
                  }
               }
            }

            $this->drop($bucketId);
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

         $stored = $this->load($id);
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

      if (sem_acquire($this->Semaphore) === false) {
         throw new RuntimeException('Failed to lock the shared-memory semaphore.');
      }
      try {
         $now = $this->now;
         try {
            return $this->advance($key, $by, $TTL, $now);
         }
         catch (RuntimeException $Exception) {
            if ($Exception->getCode() !== self::CAPACITY_ERROR) {
               throw $Exception;
            }

            // ! Reclaim only after proven capacity pressure. Stamp before the
            //   O(N) sweep so one hostile full segment can trigger at most one
            //   scan per clock tick in each worker, even when nothing expired.
            $now = $this->now;
            if ((self::$reclaimed[$this->segment] ?? null) === $now) {
               throw $Exception;
            }
            self::$reclaimed[$this->segment] = $now;

            if ($this->reclaim($now) < 1) {
               throw $Exception;
            }

            // @ The failed mutation was rolled back before reclaim(). Re-read
            //   shared state and execute it once, so collision buckets or
            //   index cleanup cannot be overwritten by a stale serialized value.
            //   Re-sample after the O(N) sweep so a short window cannot receive
            //   an expiry timestamp that already elapsed while the lock was held.
            return $this->advance($key, $by, $TTL, $this->now);
         }
      }
      finally {
         sem_release($this->Semaphore);
      }
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

         $stored = $this->load($id);
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
            $storedTags = $this->load($tagId);
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

               $stored = $this->load($member);
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
                  $this->drop($member);
                  $this->untrack($member);
               }
               else {
                  $this->put($member, $updated);
               }
            }

            if ($tagBuckets !== null) {
               unset($tagBuckets[$tag]);
               if ($tagBuckets === []) {
                  $this->untrack($tagId);
                  $this->drop($tagId);
               }
               else {
                  $this->put($tagId, [
                     'b' => self::BUCKET_VERSION,
                     't' => $tagBuckets,
                  ]);
               }
            }
            else {
               $this->untrack($tagId);
               $this->drop($tagId);
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

      sem_acquire($this->Semaphore);
      try {
         $now = $this->now;
         $count = $this->reclaim($now);
         self::$reclaimed[$this->segment] = $now;
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

      $segment = $this->segment;
      shm_remove($this->Segment);
      sem_remove($this->Semaphore);
      unset($this->Segment, $this->Semaphore, $this->segment);
      unset(self::$reclaimed[$segment]);

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

      $Semaphore = sem_get($key, 1, $this->Config->permissions, true);
      if ($Semaphore === false) {
         throw new RuntimeException('Failed to acquire the shared-memory semaphore.');
      }

      $this->guard($key, 'sem');
      if (sem_acquire($Semaphore) === false) {
         throw new RuntimeException('Failed to lock the shared-memory semaphore.');
      }

      try {
         // ! Creating and attaching one SysV segment concurrently is not
         //   reliable on every kernel/PHP combination. The semaphore is the
         //   creation barrier: only one worker can enter shm_attach() at a
         //   time, while later workers attach the already-created segment.
         $Segment = shm_attach($key, $this->Config->size, $this->Config->permissions);
         if ($Segment === false) {
            throw new RuntimeException('Failed to attach the shared-memory segment.');
         }

         try {
            $this->guard($key, 'shm');
            $Segment = $this->migrate($Segment, $key);
         }
         catch (Throwable $Throwable) {
            shm_detach($Segment);
            throw $Throwable;
         }
      }
      finally {
         sem_release($Semaphore);
      }

      $this->Segment = $Segment;
      $this->Semaphore = $Semaphore;
      $this->segment = $key;
   }

   /**
    * Adopt a segment only when it carries the current record format.
    *
    * Records used to be stored as live PHP values, which `shm_get_var()`
    * reconstructs with no allow-list at all — that is precisely why this driver
    * could not honor `Config::$classes`. Bytes left by that format cannot be
    * read safely, and an absent marker means either an old segment or a new
    * one, which want the same answer: start clean. Sessions and rate-limit
    * counters are exactly the data these consumers are built to lose.
    *
    * Runs under the creation semaphore, so only one worker ever resets.
    *
    * @param SysvSharedMemory $Segment The freshly attached segment.
    */
   private function migrate (SysvSharedMemory $Segment, int $key): SysvSharedMemory
   {
      // ?: Already ours
      if (
         shm_has_var($Segment, self::FORMAT_ID) === true
         && shm_get_var($Segment, self::FORMAT_ID) === self::FORMAT
      ) {
         return $Segment;
      }

      // @ Drop whatever is there and take the key back
      shm_remove($Segment);

      $Segment = shm_attach($key, $this->Config->size, $this->Config->permissions);
      if ($Segment === false) {
         throw new RuntimeException(
            'Failed to re-attach the shared-memory segment after a format reset.'
         );
      }

      @shm_put_var($Segment, self::FORMAT_ID, self::FORMAT);

      // :
      return $Segment;
   }

   /**
    * Read one variable back through this driver's deserialization allow-list.
    *
    * Every record is stored as an opaque string so the extension has nothing to
    * reconstruct on its own — `shm_get_var()` takes no options, so this is the
    * only shape `Config::$classes` can reach.
    */
   private function load (int $id): mixed
   {
      $stored = shm_get_var($this->Segment, $id);

      // ? Anything that is not a string predates the current format, and this
      //   driver refuses to hydrate it rather than trusting the segment
      if (is_string($stored) === false) {
         return false;
      }

      // :
      return $this->decode($stored);
   }

   /**
    * Execute one atomic counter mutation (caller holds the semaphore).
    */
   private function advance (string $key, int $by, int $TTL, int $now): int
   {
      $id = crc32($key);
      $base = 0;
      $expiry = 0;
      $live = false;
      $existed = shm_has_var($this->Segment, $id);
      $stored = $existed === true
         ? $this->load($id)
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
      $this->put(
         $id,
         $this->write($stored, $key, ['e' => $expiry, 'v' => $value, 't' => $tags])
      );
      if ($existed === false) {
         $this->track($id);
      }

      return $value;
   }

   /**
    * Remove every expired indexed record (caller holds the semaphore).
    */
   private function reclaim (int $now): int
   {
      $count = 0;

      for ($b = 0; $b < self::INDEX_BUCKETS; $b++) {
         $bucketId = self::INDEX_BAND + $b;
         if (shm_has_var($this->Segment, $bucketId) === false) {
            continue;
         }

         $bucket = $this->load($bucketId);
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

            $stored = $this->load($id);
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
                  $this->drop($id);
                  unset($bucket[$id]);
               }
               else {
                  $this->put($id, $updated);
               }
               $changed = true;
            }
         }

         if ($changed === true) {
            if ($bucket === []) {
               $this->drop($bucketId);
            }
            else {
               $this->put($bucketId, $bucket);
            }
         }
      }

      return $count;
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

   /** Persist one variable, rolling back PHP's destructive capacity failure. */
   private function put (int $id, mixed $value): void
   {
      // ! The stored representation is an opaque string, so the extension never
      //   reconstructs anything by itself. restore() below deals in that same
      //   representation, which is why it is captured raw.
      $bytes = serialize($value);

      $existed = shm_has_var($this->Segment, $id);
      $previous = $existed === true
         ? shm_get_var($this->Segment, $id)
         : null;

      if ($this->commit($id, $bytes) === true) {
         return;
      }

      $this->restore($id, $existed, $previous);

      throw new RuntimeException(
         "Shared-memory capacity exhausted while writing variable {$id}.",
         self::CAPACITY_ERROR,
      );
   }

   /** Write one raw SysV value without recording the expected capacity warning. */
   private function commit (int $id, mixed $value): bool
   {
      return @shm_put_var($this->Segment, $id, $value);
   }

   /** Restore one variable to the state captured immediately before put(). */
   private function restore (int $id, bool $existed, mixed $previous): void
   {
      if ($existed === false) {
         if (shm_has_var($this->Segment, $id) === true) {
            $this->drop($id);
         }

         return;
      }

      if ($this->commit($id, $previous) === true) {
         return;
      }

      throw new RuntimeException(
         "Failed to restore shared-memory variable {$id} after a write failure."
      );
   }

   /** Remove one shared-memory variable and fail explicitly on IPC errors. */
   private function drop (int $id): void
   {
      if (shm_remove_var($this->Segment, $id) === false) {
         throw new RuntimeException("Failed to remove shared-memory variable {$id}.");
      }
   }

   /**
    * Add a var key to its live-key index bucket (caller holds the semaphore).
    */
   private function track (int $id): void
   {
      $bucketId = self::INDEX_BAND + ($id % self::INDEX_BUCKETS);

      $bucket = shm_has_var($this->Segment, $bucketId) === true
         ? $this->load($bucketId)
         : [];
      if (is_array($bucket) === false) {
         $bucket = [];
      }

      $bucket[$id] = true;
      try {
         $this->put($bucketId, $bucket);
      }
      catch (Throwable $Throwable) {
         // ! The value was created immediately before its first index entry.
         //   Roll it back so a failed index expansion cannot leave an orphan
         //   that clear()/purge() can never discover.
         $this->drop($id);

         throw $Throwable;
      }
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

      $bucket = $this->load($bucketId);
      if (is_array($bucket) === false) {
         return;
      }

      unset($bucket[$id]);
      if ($bucket === []) {
         $this->drop($bucketId);
      }
      else {
         $this->put($bucketId, $bucket);
      }
   }

   /**
    * Add a value key to a tag's member set (caller holds the semaphore).
    */
   private function bind (string $tag, int $id): void
   {
      $tagId = self::TAG_BAND + crc32($tag);

      $existed = shm_has_var($this->Segment, $tagId);
      $stored = $existed === true
         ? $this->load($tagId)
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

      $this->put($tagId, [
         'b' => self::BUCKET_VERSION,
         't' => $tagBuckets,
      ]);
      if ($existed === false) {
         $this->track($tagId);
      }
   }
}
