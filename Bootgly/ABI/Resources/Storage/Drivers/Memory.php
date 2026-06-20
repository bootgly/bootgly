<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\Drivers;


use function array_keys;
use function fwrite;
use function ltrim;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;
use function time;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Resources\Storage\Driver;


/**
 * In-process memory storage driver.
 *
 * Keeps files in a per-instance array — no persistence, no filesystem. Useful
 * for tests and ephemeral, request-scoped storage. Directories are implicit:
 * a directory "exists" when any file is stored under its prefix.
 */
class Memory extends Driver
{
   // * Data
   /** @var array<string,string> */
   protected array $files = [];
   /** @var array<string,int> */
   protected array $times = [];


   /**
    * Upload contents to a path from a readable stream.
    *
    * @param resource $source
    * @param array<string,mixed> $options Ignored — the memory backend stores raw bytes only.
    */
   public function write (string $path, $source, array $options = []): bool
   {
      $contents = stream_get_contents($source);
      // ?
      if ($contents === false) {
         return $this->fail('Memory write: source stream read error');
      }

      $key = $this->resolve($path);
      $this->files[$key] = $contents;
      $this->times[$key] = time();

      // :
      return true;
   }

   /**
    * Download a path into a writable stream; false when it is missing.
    *
    * @param resource $sink
    */
   public function read (string $path, $sink): bool
   {
      $key = $this->resolve($path);
      // ?
      if (isset($this->files[$key]) === false) {
         return false;
      }

      // :
      return fwrite($sink, $this->files[$key]) !== false;
   }

   /**
    * Remove one file; returns true (the key no longer exists either way).
    */
   public function delete (string $path): bool
   {
      $key = $this->resolve($path);

      unset($this->files[$key], $this->times[$key]);

      // :
      return true;
   }

   /**
    * Whether a file or (implicit) directory exists at the path.
    */
   public function check (string $path): bool
   {
      $key = $this->resolve($path);

      // ?: Exact file
      if (isset($this->files[$key]) === true) {
         return true;
      }

      // ?: Implicit directory — any file stored under the prefix
      $prefix = $key === '' ? '' : "{$key}/";
      if ($prefix === '') {
         return $this->files !== [];
      }
      foreach (array_keys($this->files) as $stored) {
         if (str_starts_with($stored, $prefix) === true) {
            return true;
         }
      }

      // :
      return false;
   }

   /**
    * List file paths (disk-relative) under a directory.
    *
    * @return array<int,string>
    */
   public function list (string $path = '', bool $recursive = false): array
   {
      $key = $this->resolve($path);
      $prefix = $key === '' ? '' : "{$key}/";

      $paths = [];
      foreach (array_keys($this->files) as $stored) {
         // ? Outside the requested directory
         if ($prefix !== '' && str_starts_with($stored, $prefix) === false) {
            continue;
         }

         // ? Non-recursive: skip entries nested in subdirectories
         $relative = $prefix === '' ? $stored : substr($stored, strlen($prefix));
         if ($recursive === false && str_contains($relative, '/') === true) {
            continue;
         }

         $paths[] = $stored;
      }

      // :
      return $paths;
   }

   /**
    * Copy a file from one path to another.
    */
   public function copy (string $from, string $to): bool
   {
      $source = $this->resolve($from);
      // ?
      if (isset($this->files[$source]) === false) {
         return false;
      }

      $target = $this->resolve($to);
      $this->files[$target] = $this->files[$source];
      $this->times[$target] = time();

      // :
      return true;
   }

   /**
    * Move a file from one path to another.
    */
   public function move (string $from, string $to): bool
   {
      // ?
      if ($this->copy($from, $to) === false) {
         return false;
      }

      // :
      return $this->delete($from);
   }

   /**
    * File size in bytes; false when the path is missing.
    */
   public function measure (string $path): int|false
   {
      $key = $this->resolve($path);

      // :
      return isset($this->files[$key]) === true ? strlen($this->files[$key]) : false;
   }

   /**
    * File metadata (`size` in bytes, `modified` write time); false when missing.
    *
    * @return array{size:int,modified:int}|false
    */
   public function inspect (string $path): array|false
   {
      $key = $this->resolve($path);
      // ?
      if (isset($this->files[$key]) === false) {
         return false;
      }

      // :
      return ['size' => strlen($this->files[$key]), 'modified' => $this->times[$key] ?? 0];
   }

   /**
    * Create a directory — a no-op, since directories are implicit in memory.
    */
   public function make (string $path): bool
   {
      // :
      return true;
   }

   /**
    * Remove every file stored under a directory.
    */
   public function clear (string $path = ''): bool
   {
      $key = $this->resolve($path);
      $prefix = $key === '' ? '' : "{$key}/";

      // @
      foreach (array_keys($this->files) as $stored) {
         if ($prefix === '' || str_starts_with($stored, $prefix) === true) {
            unset($this->files[$stored], $this->times[$stored]);
         }
      }

      // :
      return true;
   }

   // ---

   /**
    * Resolve a disk-relative path into a stable storage key.
    */
   private function resolve (string $path): string
   {
      // ?
      if ($path === '') {
         return '';
      }

      // :
      return ltrim(Path::normalize($path), '/');
   }
}
