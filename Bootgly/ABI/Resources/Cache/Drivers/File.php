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
use function unserialize;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
      foreach ($tags as $tag) {
         $this->tag($tag, $key);
      }

      return true;
   }

   public function delete (string $key): bool
   {
      $file = $this->locate($key);

      // ?
      if (is_file($file) === false) {
         return true;
      }

      return @unlink($file);
   }

   public function clear (): bool
   {
      // ?
      if (is_dir($this->Config->path) === false) {
         return true;
      }

      foreach ($this->scan() as $file) {
         @unlink($file);
      }

      return true;
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
         $record = @unserialize($bytes);
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

      $record = @unserialize($bytes);
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

   public function purge (): int
   {
      // ?
      if (is_dir($this->Config->path) === false) {
         return 0;
      }

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

         $record = @unserialize($bytes);
         if (is_array($record) === false) {
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
    * @return array{0: bool, 1: mixed} [hit, value]
    */
   private function read (string $key, int $now): array
   {
      $file = $this->locate($key);

      // ?
      if (is_file($file) === false) {
         return [false, null];
      }

      $bytes = @file_get_contents($file);
      if ($bytes === false || $bytes === '') {
         return [false, null];
      }

      $record = @unserialize($bytes);
      if (is_array($record) === false || ($record['key'] ?? null) !== $key) {
         return [false, null];
      }

      $Item = $record['Item'] ?? null;
      if ($Item instanceof Item === false) {
         return [false, null];
      }

      // ? Expired
      if ($Item->expiry !== 0 && $Item->expiry <= $now) {
         @unlink($file);

         return [false, null];
      }

      // :
      return [true, $Item->value];
   }
}
