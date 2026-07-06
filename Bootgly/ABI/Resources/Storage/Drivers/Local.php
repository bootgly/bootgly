<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\Drivers;


use const DIRECTORY_SEPARATOR;
use function copy;
use function dirname;
use function fclose;
use function file_exists;
use function filemtime;
use function filesize;
use function fopen;
use function getmypid;
use function is_dir;
use function is_file;
use function ltrim;
use function mkdir;
use function rename;
use function rmdir;
use function scandir;
use function str_starts_with;
use function stream_copy_to_stream;
use function strlen;
use function substr;
use function uniqid;
use function unlink;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Resources\Storage\Driver;


/**
 * Local filesystem storage driver (default).
 *
 * Always available — no extension required. Paths are normalized and jailed
 * inside the disk root: `resolve()` collapses `..`/`.` textually, and every
 * operation additionally runs a `realpath()` containment check (`File->guard()`)
 * so a symlink planted inside the root cannot escape it. Writes are atomic (temp
 * file + rename) and listings use a recursive SPL walk.
 */
class Local extends Driver
{
   // * Data
   protected File $Jail;


   /**
    * Build the local driver and bind a realpath jail guard to the disk root.
    *
    * @param array<string,mixed> $options
    */
   public function __construct (string $root, array $options = [])
   {
      parent::__construct($root, $options);

      // * Data
      $this->Jail = new File($this->root, $this->root);
   }

   /**
    * Upload to a path from a readable stream, creating parent directories as needed.
    *
    * @param resource $source
    * @param array<string,mixed> $options Ignored — the local filesystem has no object metadata.
    */
   public function write (string $path, $source, array $options = []): bool
   {
      $this->error = '';
      $file = $this->resolve($path);

      // ! Ensure the parent directory exists
      $dir = dirname($file);
      if (is_dir($dir) === false) {
         @mkdir($dir, 0775, true);
      }
      // ? Jail: the (now-existing) parent must resolve inside the root
      if ($this->guard($dir) === true) {
         return $this->fail("Local write {$file}: path escapes the disk root");
      }

      // @ Atomic write: stream into a temp file + rename
      $temp = $file . '.' . getmypid() . '.' . uniqid('', true) . '.tmp';
      $out = @fopen($temp, 'wb');
      if ($out === false) {
         return $this->fail("Local write {$file}: cannot open temp file");
      }
      $copied = @stream_copy_to_stream($source, $out);
      @fclose($out);
      if ($copied === false) {
         @unlink($temp);

         return $this->fail("Local write {$file}: stream copy failed");
      }
      if (@rename($temp, $file) === false) {
         @unlink($temp);

         return $this->fail("Local write {$file}: rename failed");
      }

      // :
      return true;
   }

   /**
    * Download a path into a writable stream; false when it is missing or unreadable.
    *
    * @param resource $sink
    */
   public function read (string $path, $sink): bool
   {
      $this->error = '';
      $file = $this->resolve($path);

      // ?
      if (is_file($file) === false) {
         return $this->fail("Local read {$file}: not found");
      }
      // ? Jail: refuse to read through a symlink that escapes the root
      if ($this->guard($file) === true) {
         return $this->fail("Local read {$file}: path escapes the disk root");
      }

      $in = @fopen($file, 'rb');
      if ($in === false) {
         return $this->fail("Local read {$file}: cannot open");
      }
      $copied = @stream_copy_to_stream($in, $sink);
      @fclose($in);

      // :
      return $copied !== false
         ? true
         : $this->fail("Local read {$file}: stream copy failed");
   }

   /**
    * Remove one file; returns true when the file no longer exists.
    */
   public function delete (string $path): bool
   {
      $this->error = '';
      $file = $this->resolve($path);

      // ?: Idempotent — nothing to delete
      if (is_file($file) === false) {
         return true;
      }
      // ? Jail: refuse to unlink through a symlink that escapes the root
      if ($this->guard($file) === true) {
         return $this->fail("Local delete {$file}: path escapes the disk root");
      }

      // :
      return @unlink($file) === true
         ? true
         : $this->fail("Local delete {$file}: unlink failed");
   }

   /**
    * Whether a file or directory exists at the path.
    */
   public function check (string $path): bool
   {
      $this->error = '';
      $resolved = $this->resolve($path);

      // ? Jail: a path that escapes the root is reported as absent
      if ($this->guard($resolved) === true) {
         return false;
      }

      // :
      return is_file($resolved) === true || is_dir($resolved) === true;
   }

   /**
    * List file paths (disk-relative) under a directory.
    *
    * @return array<int,string>
    */
   public function list (string $path = '', bool $recursive = false): array
   {
      $this->error = '';
      $dir = $this->resolve($path);
      // ?
      if (is_dir($dir) === false) {
         return [];
      }
      // ? Jail: never walk a directory that escapes the root
      if ($this->guard($dir) === true) {
         $this->error = "Local list {$dir}: path escapes the disk root";

         return [];
      }

      $root = $this->root . DIRECTORY_SEPARATOR;
      $paths = [];

      // @
      if ($recursive === true) {
         $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
         );
         foreach ($Iterator as $Info) {
            if ($Info instanceof SplFileInfo === true && $Info->isFile() === true) {
               $paths[] = $this->relativize($Info->getPathname(), $root);
            }
         }
      }
      else {
         foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($full) === true) {
               $paths[] = $this->relativize($full, $root);
            }
         }
      }

      // :
      return $paths;
   }

   /**
    * Copy a file from one path to another within the disk.
    */
   public function copy (string $from, string $to): bool
   {
      $this->error = '';
      $source = $this->resolve($from);
      // ?
      if (is_file($source) === false) {
         return $this->fail("Local copy {$source}: source not found");
      }
      // ? Jail: source must resolve inside the root
      if ($this->guard($source) === true) {
         return $this->fail("Local copy {$source}: path escapes the disk root");
      }

      $target = $this->resolve($to);
      $dir = dirname($target);
      if (is_dir($dir) === false) {
         @mkdir($dir, 0775, true);
      }
      // ? Jail: target parent must resolve inside the root
      if ($this->guard($dir) === true) {
         return $this->fail("Local copy {$target}: path escapes the disk root");
      }

      // :
      return @copy($source, $target) === true
         ? true
         : $this->fail("Local copy {$target}: copy failed");
   }

   /**
    * Move (rename) a file from one path to another within the disk.
    */
   public function move (string $from, string $to): bool
   {
      $this->error = '';
      $source = $this->resolve($from);
      // ?
      if (is_file($source) === false) {
         return $this->fail("Local move {$source}: source not found");
      }
      // ? Jail: source must resolve inside the root
      if ($this->guard($source) === true) {
         return $this->fail("Local move {$source}: path escapes the disk root");
      }

      $target = $this->resolve($to);
      $dir = dirname($target);
      if (is_dir($dir) === false) {
         @mkdir($dir, 0775, true);
      }
      // ? Jail: target parent must resolve inside the root
      if ($this->guard($dir) === true) {
         return $this->fail("Local move {$target}: path escapes the disk root");
      }

      // :
      return @rename($source, $target) === true
         ? true
         : $this->fail("Local move {$target}: rename failed");
   }

   /**
    * File size in bytes; false when the path is missing.
    */
   public function measure (string $path): int|false
   {
      $this->error = '';
      $file = $this->resolve($path);
      // ?
      if (is_file($file) === false) {
         return false;
      }
      // ? Jail: a path that escapes the root is reported as missing
      if ($this->guard($file) === true) {
         return $this->fail("Local measure {$file}: path escapes the disk root");
      }

      // :
      return @filesize($file);
   }

   /**
    * File metadata (`size` in bytes, `modified` Unix mtime); false when missing.
    *
    * @return array{size:int,modified:int}|false
    */
   public function inspect (string $path): array|false
   {
      $this->error = '';
      $resolved = $this->resolve($path);
      // ?
      if (file_exists($resolved) === false) {
         return false;
      }
      // ? Jail: a path that escapes the root is reported as missing
      if ($this->guard($resolved) === true) {
         return $this->fail("Local inspect {$resolved}: path escapes the disk root");
      }

      $size = @filesize($resolved);
      $modified = @filemtime($resolved);
      // ?
      if ($size === false || $modified === false) {
         return false;
      }

      // :
      return ['size' => $size, 'modified' => $modified];
   }

   /**
    * Create a directory (recursively); returns true when it exists afterwards.
    */
   public function make (string $path): bool
   {
      $this->error = '';
      $dir = $this->resolve($path);
      // ?
      if (is_dir($dir) === true) {
         return true;
      }

      if (@mkdir($dir, 0775, true) === false) {
         return $this->fail("Local make {$dir}: mkdir failed");
      }
      // ? Jail: a symlinked component could place the new directory outside the root
      if ($this->guard($dir) === true) {
         return $this->fail("Local make {$dir}: path escapes the disk root");
      }

      // :
      return true;
   }

   /**
    * Remove every entry under a directory, keeping the directory itself.
    */
   public function clear (string $path = ''): bool
   {
      $this->error = '';
      $dir = $this->resolve($path);
      // ?
      if (is_dir($dir) === false) {
         return true;
      }
      // ? Jail: never recurse-delete a directory that escapes the root
      if ($this->guard($dir) === true) {
         return $this->fail("Local clear {$dir}: path escapes the disk root");
      }

      // @ Depth-first so directories empty before they are removed
      $Iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );
      $cleared = true;
      foreach ($Iterator as $Info) {
         if ($Info instanceof SplFileInfo === false) {
            continue;
         }
         $removed = $Info->isDir() === true
            ? @rmdir($Info->getPathname())
            : @unlink($Info->getPathname());
         $cleared = $removed && $cleared;
      }

      // :
      return $cleared;
   }

   // ---

   /**
    * Resolve a disk-relative path to an absolute path jailed inside the root.
    */
   private function resolve (string $path): string
   {
      // ?
      if ($path === '') {
         return $this->root;
      }

      // @ Normalize collapses `..`/`.`; ltrim keeps the result root-relative
      $relative = ltrim(Path::normalize($path), DIRECTORY_SEPARATOR);
      if ($relative === '') {
         return $this->root;
      }

      // :
      return $this->root . DIRECTORY_SEPARATOR . $relative;
   }

   /**
    * Whether an absolute path breaches the disk root once symlinks are resolved
    * (true = blocked). Defers to `File->guard()` (realpath containment,
    * fail-closed); the root itself is trivially contained.
    */
   private function guard (string $absolute): bool
   {
      // ?
      if ($absolute === $this->root) {
         return false;
      }

      // :
      return $this->Jail->guard($absolute);
   }

   /**
    * Strip the root prefix from an absolute path to make it disk-relative.
    */
   private function relativize (string $full, string $root): string
   {
      // :
      return str_starts_with($full, $root) === true
         ? substr($full, strlen($root))
         : $full;
   }
}
