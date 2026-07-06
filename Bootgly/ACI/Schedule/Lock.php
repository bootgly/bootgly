<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Schedule;


use const BOOTGLY_STORAGE_DIR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use function clearstatcache;
use function fclose;
use function flock;
use function fopen;
use function is_dir;
use function is_file;
use function mkdir;
use function unlink;


/**
 * Per-job advisory file lock for overlap prevention.
 *
 * A non-blocking exclusive `flock` on `storage/schedule/<id>.lock`; a second
 * holder (e.g. an overlapping worker invocation) fails to acquire and the run
 * is skipped. Same idiom as `ACI/Process/State::lock()`.
 */
final class Lock
{
   // * Data
   /**
    * The lock file path.
    */
   public private(set) string $file;

   // * Metadata
   /** @var resource|null */
   private mixed $handle = null;


   public function __construct (string $id)
   {
      $dir = BOOTGLY_STORAGE_DIR . 'schedule/';

      // ! Ensure the state directory exists
      if (is_dir($dir) === false) {
         @mkdir($dir, 0755, true);
      }

      $this->file = "{$dir}{$id}.lock";
   }

   /**
    * Try to acquire an exclusive, non-blocking lock.
    *
    * Returns false when another holder already owns it (overlap).
    */
   public function acquire (): bool
   {
      $this->handle = $this->handle ?: (fopen($this->file, 'a+') ?: null);

      // ?
      if ($this->handle === null) {
         return false;
      }

      // :
      return flock($this->handle, LOCK_EX | LOCK_NB);
   }

   /**
    * Release the lock and remove the lock file.
    */
   public function release (): void
   {
      // ?
      if ($this->handle === null) {
         return;
      }

      flock($this->handle, LOCK_UN);
      fclose($this->handle);

      $this->handle = null;

      clearstatcache();

      if (is_file($this->file)) {
         @unlink($this->file);
      }
   }
}
