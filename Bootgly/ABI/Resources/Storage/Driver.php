<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use function rtrim;


/**
 * Storage driver contract.
 *
 * Concrete drivers (Local, Memory, ...) implement one backend each. Every path
 * arriving here is disk-relative; the driver resolves it against its own root
 * and is responsible for keeping access jailed inside that root.
 */
abstract class Driver
{
   // * Config
   public private(set) string $root;

   // * Data
   /** @var array<string,mixed> */
   protected array $options;

   // * Metadata
   /**
    * Human-readable reason for the last failed operation (valid right after a `false`
    * return). Exposed so a higher layer can log it — ABI drivers cannot depend on the
    * ACI logger (forward-only layering), so failures are surfaced here instead.
    */
   public protected(set) string $error = '';


   /**
    * Build a driver bound to one disk root.
    *
    * @param array<string,mixed> $options
    */
   public function __construct (string $root, array $options = [])
   {
      // * Config
      // ? Normalize a trailing slash so drivers build clean paths (keep bare "/")
      $this->root = $root === '/' ? '/' : rtrim($root, '/');

      // * Data
      $this->options = $options;
   }

   /**
    * Upload to a path from a readable stream, creating parent directories as needed.
    *
    * @param resource $source
    * @param array<string,mixed> $options Driver-specific write options (e.g. S3 `type`, `meta`).
    */
   abstract public function write (string $path, $source, array $options = []): bool;
   /**
    * Download a path into a writable stream; false when it is missing or unreadable.
    *
    * @param resource $sink
    */
   abstract public function read (string $path, $sink): bool;
   /**
    * Remove one file; returns true when the path no longer exists.
    */
   abstract public function delete (string $path): bool;
   /**
    * Whether a file or directory exists at the path.
    */
   abstract public function check (string $path): bool;
   /**
    * List file paths (disk-relative) under a directory.
    *
    * @return array<int,string>
    */
   abstract public function list (string $path = '', bool $recursive = false): array;
   /**
    * Copy a file from one path to another within the disk.
    */
   abstract public function copy (string $from, string $to): bool;
   /**
    * Move (rename) a file from one path to another within the disk.
    */
   abstract public function move (string $from, string $to): bool;
   /**
    * File size in bytes; false when the path is missing.
    */
   abstract public function measure (string $path): int|false;
   /**
    * File metadata (`size` in bytes, `modified` Unix mtime); false when missing.
    *
    * @return array{size:int,modified:int}|false
    */
   abstract public function inspect (string $path): array|false;
   /**
    * Create a directory (recursively); returns true when it exists afterwards.
    */
   abstract public function make (string $path): bool;
   /**
    * Remove every entry under a directory, keeping the directory itself.
    */
   abstract public function clear (string $path = ''): bool;

   /**
    * Record the reason for a failed operation and return the bool failure value, so a
    * caller can read `$error` (and a higher layer log it) right after a `false` return.
    */
   protected function fail (string $message): false
   {
      $this->error = $message;

      // :
      return false;
   }
}
