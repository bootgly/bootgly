<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage\Drivers;


use const DIRECTORY_SEPARATOR;
use const PHP_MAXPATHLEN;
use function clearstatcache;
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
use function realpath;
use function rename;
use function rmdir;
use function scandir;
use function str_contains;
use function str_starts_with;
use function stream_copy_to_stream;
use function strlen;
use function substr;
use function uniqid;
use function unlink;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use Bootgly\ABI\Code\__String\Path;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Resources\Storage\Driver;


/**
 * Local filesystem storage driver (default).
 *
 * Always available — no extension required. Paths are normalized and jailed
 * inside the disk root: `resolve()` collapses `..`/`.` textually and refuses a
 * NUL byte, and every operation additionally runs a `realpath()` containment
 * check (`File->guard()`) so a symlink planted inside the root cannot escape it.
 * Directories are jailed before they are created: the deepest one that already
 * exists must resolve inside the root, so nothing is created through an escaping
 * symlink. Writes are atomic (temp file + rename) and listings use a recursive
 * SPL walk.
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
      // ? A NUL byte names no file
      if ($file === null) {
         return $this->fail('Local write: path contains a NUL byte');
      }

      // ! Ensure the parent directory exists inside the root
      $dir = dirname($file);
      if ($this->prepare($dir, "write {$file}") === false) {
         return false;
      }

      // @ Atomic write: stream into a temp file in the jailed parent + rename
      $temp = "{$dir}/" . getmypid() . '.' . uniqid('', true) . '.tmp';
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
      // ? A NUL byte names no file
      if ($file === null) {
         return $this->fail('Local read: path contains a NUL byte');
      }

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
      // ? A NUL byte names no file
      if ($file === null) {
         return $this->fail('Local delete: path contains a NUL byte');
      }

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
      // ? A NUL byte names no file
      if ($resolved === null) {
         return $this->fail('Local check: path contains a NUL byte');
      }

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
      // ? A NUL byte names no directory
      if ($dir === null) {
         $this->error = 'Local list: path contains a NUL byte';

         return [];
      }
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
      $target = $this->resolve($to);
      // ? A NUL byte names no file
      if ($source === null || $target === null) {
         return $this->fail('Local copy: path contains a NUL byte');
      }

      // ?
      if (is_file($source) === false) {
         return $this->fail("Local copy {$source}: source not found");
      }
      // ? Jail: source must resolve inside the root
      if ($this->guard($source) === true) {
         return $this->fail("Local copy {$source}: path escapes the disk root");
      }

      // ! Ensure the target's parent directory exists inside the root
      $dir = dirname($target);
      if ($this->prepare($dir, "copy {$target}") === false) {
         return false;
      }

      // @ Atomic copy: into a temp file inside the jailed parent + rename, so a
      //   symlinked target is replaced instead of written through (as write() and
      //   move() already do — `copy()` is the only path that follows the link)
      $temp = "{$dir}/" . getmypid() . '.' . uniqid('', true) . '.tmp';
      if (@copy($source, $temp) === false) {
         @unlink($temp);

         return $this->fail("Local copy {$target}: copy failed");
      }
      if (@rename($temp, $target) === false) {
         @unlink($temp);

         return $this->fail("Local copy {$target}: copy failed");
      }

      // :
      return true;
   }

   /**
    * Move (rename) a file from one path to another within the disk.
    */
   public function move (string $from, string $to): bool
   {
      $this->error = '';
      $source = $this->resolve($from);
      $target = $this->resolve($to);
      // ? A NUL byte names no file
      if ($source === null || $target === null) {
         return $this->fail('Local move: path contains a NUL byte');
      }

      // ?
      if (is_file($source) === false) {
         return $this->fail("Local move {$source}: source not found");
      }
      // ? Jail: source must resolve inside the root
      if ($this->guard($source) === true) {
         return $this->fail("Local move {$source}: path escapes the disk root");
      }

      // ! Ensure the target's parent directory exists inside the root
      if ($this->prepare(dirname($target), "move {$target}") === false) {
         return false;
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
      // ? A NUL byte names no file
      if ($file === null) {
         return $this->fail('Local measure: path contains a NUL byte');
      }
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
      // ? A NUL byte names no file
      if ($resolved === null) {
         return $this->fail('Local inspect: path contains a NUL byte');
      }
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
    * Create a directory (recursively) inside the disk root; returns true when it exists
    * there afterwards — false for one that resolves outside the root, existing or not.
    *
    * Each call re-checks the tree and flushes PHP's realpath cache (see `prepare()`), so
    * make a directory once at setup rather than on every request.
    */
   public function make (string $path): bool
   {
      $this->error = '';
      $dir = $this->resolve($path);
      // ? A NUL byte names no directory
      if ($dir === null) {
         return $this->fail('Local make: path contains a NUL byte');
      }

      // :
      return $this->prepare($dir, "make {$dir}");
   }

   /**
    * Remove every entry under a directory, keeping the directory itself.
    */
   public function clear (string $path = ''): bool
   {
      $this->error = '';
      $dir = $this->resolve($path);
      // ? A NUL byte names no directory
      if ($dir === null) {
         return $this->fail('Local clear: path contains a NUL byte');
      }
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
    * Resolve a disk-relative path to an absolute path jailed inside the root, or null
    * when the path cannot name a file (it carries a NUL byte).
    */
   private function resolve (string $path): null|string
   {
      // ? NUL names no file — refused on the raw path, before `Path::normalize()` can
      //   drop the component carrying it (`"x\0/../y"` normalizes to `y`)
      if (str_contains($path, "\0") === true) {
         return null;
      }
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
    * Ensure a directory exists inside the disk root, jailing it before anything is
    * created; false (with the reason on `$error`) when it escapes or cannot be made.
    *
    * The deepest directory that already exists on the way up from `$dir` is judged
    * first — the root, created on demand, caps the climb — so `mkdir()` never follows
    * an escaping symlink: a link below that ancestor is dangling or names a file, and
    * the kernel refuses to create through it. The result is judged again after
    * creation. The realpath cache is flushed first, so a directory swapped for a
    * link since an earlier check is judged as it is now (a successful write flushes
    * it anyway, through `rename()`). The check and `mkdir()` act on path names, so a
    * component swapped between them is not caught here (PHP has no `mkdirat()`).
    */
   private function prepare (string $dir, string $operation): bool
   {
      // ? A name of PATH_MAX bytes or more can never exist — refused before the climb,
      //   whose cost grows with the square of the path length
      if (strlen($dir) >= PHP_MAXPATHLEN) {
         return $this->fail("Local {$operation}: path too long");
      }
      // ! Judge the tree as it is now, not as an earlier check cached it. Only a whole-cache
      //   flush is sound: a per-path flush misses a stale ancestor or link target. The root
      //   is decided by name, so it needs none
      if ($dir !== $this->root) {
         clearstatcache(true);
      }
      // ! The deepest existing directory on the way up — never above the root
      $parent = $dir;
      while (is_dir($parent) === false && $parent !== $this->root && $parent !== dirname($parent)) {
         $parent = dirname($parent);
      }
      // ? Jail before mkdir(): an escaping ancestor is refused while nothing exists yet
      if ($this->guard($parent) === true) {
         return $this->fail("Local {$operation}: path escapes the disk root");
      }
      // ?: Already there
      if ($parent === $dir && is_dir($dir) === true) {
         return true;
      }

      // @ Create the missing tail (a concurrent creator of the same tail is not a failure)
      if (@mkdir($dir, 0775, true) === false && is_dir($dir) === false) {
         return $this->fail("Local {$operation}: mkdir failed");
      }
      // ? Jail again: what now exists must still resolve inside the root
      if ($this->guard($dir) === true) {
         return $this->fail("Local {$operation}: path escapes the disk root");
      }

      // :
      return true;
   }

   /**
    * Whether an absolute path breaches the disk root once symlinks are resolved
    * (true = blocked). Defers to `File->guard()` (realpath containment,
    * fail-closed), which only matches entries below the root — so the root itself,
    * and any in-root path resolving to it (a link to the root), is contained too.
    */
   private function guard (string $absolute): bool
   {
      // ?
      if ($absolute === $this->root) {
         return false;
      }
      // ?: Below the root
      if ($this->Jail->guard($absolute) === false) {
         return false;
      }

      // : The root under another name is still the root
      $resolved = realpath($absolute);

      return $resolved === false || $resolved !== realpath($this->root);
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
