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


use const FILE_APPEND;
use const LOCK_EX;
use const LOCK_UN;
use function array_unique;
use function dirname;
use function explode;
use function fclose;
use function fflush;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function getmypid;
use function hash;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function mkdir;
use function rename;
use function rewind;
use function serialize;
use function str_ends_with;
use function stream_get_contents;
use function substr;
use function time;
use function trim;
use function uniqid;
use function unlink;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use Bootgly\ABI\Resources\Cache\Driver;
use Bootgly\ABI\Resources\Cache\Item;


/**
 * Filesystem cache driver.
 *
 * Always available — no extension required. One file per key, sharded by hash
 * prefix; writes are atomic (temp file + rename) and increment() is guarded by
 * an exclusive file lock. Raw filesystem calls are used throughout (the
 * Efficiency principle) including a direct SPL recursive walk for clear/purge.
 */
class File extends Driver
{
   private const string LOCK = '.cache.lock';
   /**
    * The record wrapper this driver writes — the only class it reconstructs
    * beyond the ones the application declared in `Config::$classes`.
    */
   protected const array WRAPPERS = [Item::class];


   public function fetch (string $key): mixed
   {
      $clock = $this->Config->clock;
      $now = $clock === null ? time() : (int) $clock();

      return $this->read($key, $now)[1];
   }

   /**
    * @param array<int,string> $tags
    */
   public function store (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      $Lock = $this->lock();
      try {
         return $this->persist($key, $value, $TTL, $tags);
      }
      finally {
         $this->release($Lock);
      }
   }

   /** @param array<int,string> $tags */
   public function create (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      $Lock = $this->lock();
      try {
         $clock = $this->Config->clock;
         $now = $clock === null ? time() : (int) $clock();
         // ? Occupied — or occupied by bytes THIS cache cannot decode. read()
         //   removes an expired record, so a file still standing after it means
         //   the slot is taken by a live record that merely names a class this
         //   instance did not declare. Reporting the slot free would let create()
         //   overwrite data another reader still owns
         if ($this->read($key, $now, true)[0] || is_file($this->locate($key)) === true) {
            return false;
         }

         return $this->persist($key, $value, $TTL, $tags);
      }
      finally {
         $this->release($Lock);
      }
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
      $Lock = $this->lock();
      try {
         $clock = $this->Config->clock;
         $now = $clock === null ? time() : (int) $clock();
         [$live, $current] = $this->read($key, $now, true);
         if ($live === false || $current !== $expected) {
            return false;
         }

         return $this->persist($key, $value, $TTL, $tags);
      }
      finally {
         $this->release($Lock);
      }
   }

   public function evict (string $key, mixed $expected): bool
   {
      $Lock = $this->lock();
      try {
         $clock = $this->Config->clock;
         $now = $clock === null ? time() : (int) $clock();
         [$live, $current] = $this->read($key, $now, true);
         if ($live === false || $current !== $expected) {
            return false;
         }

         return @unlink($this->locate($key));
      }
      finally {
         $this->release($Lock);
      }
   }

   public function renew (string $key, int $TTL = 0): bool
   {
      $Lock = $this->lock();
      try {
         $clock = $this->Config->clock;
         $now = $clock === null ? time() : (int) $clock();
         [$live, $value, $tags] = $this->read($key, $now, true);
         if ($live === false) {
            return false;
         }

         // ! The read and write share the same lock, so no stale value can be
         //   restored while only the expiry metadata is renewed.
         return $this->persist($key, $value, $TTL, $tags, false);
      }
      finally {
         $this->release($Lock);
      }
   }

   /** @param array<int,string> $tags */
   private function persist (
      string $key,
      mixed $value,
      int $TTL,
      array $tags,
      bool $bind = true,
   ): bool
   {
      $clock = $this->Config->clock;
      $now = $clock === null ? time() : (int) $clock();
      $expiry = $TTL > 0 ? $now + $TTL : 0;

      $file = $this->locate($key);

      $record = ['key' => $key, 'Item' => new Item($value, $expiry, $tags)];
      $bytes = serialize($record);

      // @ Atomic write: temp file + rename
      $pid = getmypid();
      $uid = uniqid('', true);
      $temp = "{$file}.{$pid}.{$uid}.tmp";

      // ? Create the shard dir lazily — only when the first write fails
      $written = @file_put_contents($temp, $bytes);
      if ($written === false) {
         $this->prepare($file);
         $written = @file_put_contents($temp, $bytes);
      }
      if ($written === false) {
         return false;
      }
      if (@rename($temp, $file) === false) {
         @unlink($temp);

         return false;
      }

      // @ Record tag membership
      if ($bind) {
         foreach ($tags as $tag) {
            $this->tag($tag, $key);
         }
      }

      return true;
   }

   public function delete (string $key): bool
   {
      $Lock = $this->lock();
      try {
         $file = $this->locate($key);

         // ?
         if (is_file($file) === false) {
            return true;
         }

         return @unlink($file);
      }
      finally {
         $this->release($Lock);
      }
   }

   public function clear (): bool
   {
      $Lock = $this->lock();
      try {
         $lockPath = $this->Config->path . '/' . self::LOCK;

         foreach ($this->scan() as $file) {
            if ($file !== $lockPath) {
               @unlink($file);
            }
         }

         return true;
      }
      finally {
         $this->release($Lock);
      }
   }

   public function check (string $key): bool
   {
      $clock = $this->Config->clock;
      $now = $clock === null ? time() : (int) $clock();

      return $this->read($key, $now)[0];
   }

   public function increment (string $key, int $by = 1, int $TTL = 0): int
   {
      $clock = $this->Config->clock;
      $now = $clock === null ? time() : (int) $clock();

      $Lock = $this->lock();
      try {
         $file = $this->locate($key);

         // ? Open (creating the file); create the shard dir lazily only on failure
         $handle = @fopen($file, 'c+b');
         if ($handle === false) {
            $this->prepare($file);
            $handle = @fopen($file, 'c+b');
            if ($handle === false) {
               return 0;
            }
         }

         flock($handle, LOCK_EX);

         // @ Read current counter under lock
         $base = 0;
         $expiry = 0;
         $live = false;
         $bytes = stream_get_contents($handle);
         if ($bytes !== false && $bytes !== '') {
            $record = $this->decode($bytes);
            if (is_array($record) === true && ($record['key'] ?? null) === $key) {
               $Item = $record['Item'] ?? null;
               if ($Item instanceof Item === true && ($Item->expiry === 0 || $Item->expiry > $now)) {
                  if (is_int($Item->value) === true) {
                     $base = $Item->value;
                  }
                  $expiry = $Item->expiry;
                  $live = true;
               }
            }
         }

         // @ Compute and persist (TTL applies only when creating the counter)
         $value = $base + $by;
         if ($TTL > 0 && $live === false) {
            $expiry = $now + $TTL;
         }

         $out = serialize(['key' => $key, 'Item' => new Item($value, $expiry, [])]);
         rewind($handle);
         ftruncate($handle, 0);
         fwrite($handle, $out);
         fflush($handle);
         flock($handle, LOCK_UN);
         fclose($handle);

         return $value;
      }
      finally {
         $this->release($Lock);
      }
   }

   public function remain (string $key): int
   {
      $clock = $this->Config->clock;
      $now = $clock === null ? time() : (int) $clock();

      $file = $this->locate($key);
      // ?
      if (is_file($file) === false) {
         return -2;
      }

      $bytes = @file_get_contents($file);
      if ($bytes === false || $bytes === '') {
         return -2;
      }

      $record = $this->decode($bytes);
      if (is_array($record) === false || ($record['key'] ?? null) !== $key) {
         return -2;
      }

      $Item = $record['Item'] ?? null;
      if ($Item instanceof Item === false) {
         return -2;
      }

      // ?: No expiry
      if ($Item->expiry === 0) {
         return -1;
      }
      // ? Expired
      if ($Item->expiry <= $now) {
         return -2;
      }

      // :
      return $Item->expiry - $now;
   }

   public function invalidate (string $tag): bool
   {
      $Lock = $this->lock();
      try {
         $hash = hash('xxh3', $tag);
         $file = "{$this->Config->path}/@tags/{$hash}.tag";

         // ?
         if (is_file($file) === false) {
            return true;
         }

         $bytes = @file_get_contents($file);
         if ($bytes !== false && $bytes !== '') {
            $keys = array_unique(explode("\n", trim($bytes)));
            foreach ($keys as $member) {
               if ($member === '') {
                  continue;
               }
               @unlink($this->locate($member));
            }
         }

         @unlink($file);

         return true;
      }
      finally {
         $this->release($Lock);
      }
   }

   public function purge (): int
   {
      $Lock = $this->lock();
      try {
         $clock = $this->Config->clock;
         $now = $clock === null ? time() : (int) $clock();

         $count = 0;

         foreach ($this->scan() as $file) {
            if (str_ends_with($file, '.cache') === false) {
               continue;
            }

            $bytes = @file_get_contents($file);
            if ($bytes === false || $bytes === '') {
               continue;
            }

            $record = $this->decode($bytes);
            if (is_array($record) === false) {
               continue;
            }

            // ? A record only belongs to the file its own key hashes to — the
            //   same binding the keyed reads assert, expressed for a
            //   path-driven scan. Without it this sink accepts any blob merely
            //   shaped like a record, including one persist() could never write
            $key = $record['key'] ?? null;
            if (is_string($key) === false || $this->locate($key) !== $file) {
               continue;
            }

            $Item = $record['Item'] ?? null;
            if ($Item instanceof Item === false) {
               continue;
            }

            if ($Item->expiry !== 0 && $Item->expiry <= $now && @unlink($file) === true) {
               $count++;
            }
         }

         return $count;
      }
      finally {
         $this->release($Lock);
      }
   }

   // ---

   /**
    * Recursively collect every file path under the cache directory.
    *
    * @return array<int,string>
    */
   private function scan (): array
   {
      $files = [];

      $Iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($this->Config->path, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );

      foreach ($Iterator as $Info) {
         if ($Info instanceof SplFileInfo === true && $Info->isFile() === true) {
            $files[] = $Info->getPathname();
         }
      }

      return $files;
   }

   /**
    * Resolve the on-disk path for a key (hash-sharded).
    */
   private function locate (string $key): string
   {
      $hash = hash('xxh3', $key);
      $shard = substr($hash, 0, 2);

      return "{$this->Config->path}/{$shard}/{$hash}.cache";
   }

   /**
    * Ensure the shard directory for a file exists.
    */
   private function prepare (string $file): void
   {
      $dir = dirname($file);

      if (is_dir($dir) === false) {
         @mkdir($dir, 0775, true);
      }
   }

   /**
    * Append a key to a tag index file.
    */
   private function tag (string $tag, string $key): void
   {
      $dir = "{$this->Config->path}/@tags";
      if (is_dir($dir) === false) {
         @mkdir($dir, 0775, true);
      }

      $hash = hash('xxh3', $tag);
      @file_put_contents("{$dir}/{$hash}.tag", "{$key}\n", FILE_APPEND | LOCK_EX);
   }

   /**
    * Read a record under no lock.
    *
    * @return array{0: bool, 1: mixed, 2: array<int,string>} [hit, value, tags]
    */
   private function read (string $key, int $now, bool $remove = false): array
   {
      $file = $this->locate($key);

      // ?
      if (is_file($file) === false) {
         return [false, null, []];
      }

      $bytes = @file_get_contents($file);
      if ($bytes === false || $bytes === '') {
         return [false, null, []];
      }

      $record = $this->decode($bytes);
      if (is_array($record) === false || ($record['key'] ?? null) !== $key) {
         return [false, null, []];
      }

      $Item = $record['Item'] ?? null;
      if ($Item instanceof Item === false) {
         return [false, null, []];
      }

      // ? Expired
      if ($Item->expiry !== 0 && $Item->expiry <= $now) {
         if ($remove) {
            @unlink($file);
         }

         return [false, null, []];
      }

      // :
      return [true, $Item->value, $Item->tags];
   }

   /**
    * Open and acquire the stable directory-wide mutation lock.
    *
    * @return resource
    */
   private function lock (): mixed
   {
      if (is_dir($this->Config->path) === false) {
         @mkdir($this->Config->path, 0775, true);
      }

      $path = $this->Config->path . '/' . self::LOCK;
      $Lock = @fopen($path, 'c+b');
      if ($Lock === false || @flock($Lock, LOCK_EX) === false) {
         if ($Lock !== false) {
            @fclose($Lock);
         }
         throw new RuntimeException('Failed to lock the File cache.');
      }

      return $Lock;
   }

   /** @param resource $Lock */
   private function release (mixed $Lock): void
   {
      @flock($Lock, LOCK_UN);
      @fclose($Lock);
   }
}
