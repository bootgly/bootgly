<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema;


use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use function dirname;
use function fclose;
use function file_get_contents;
use function flock;
use function fopen;
use function fwrite;
use function getmypid;
use function is_dir;
use function is_file;
use function mkdir;
use function posix_get_last_error;
use function posix_kill;
use function preg_match;
use function time;
use function unlink;


/**
 * Local migration lock file.
 *
 * The `.guard` sidecar is persistent by design. It is the reusable advisory
 * `flock()` target that serializes create/reclaim/write critical sections;
 * `release()` removes only the ownership lock file.
 */
class Lock
{
   // * Config
   public private(set) string $file;

   // * Data
   public private(set) bool $locked = false;

   // * Metadata
   // ...


   public function __construct (string $file)
   {
      // * Config
      $this->file = $file;
   }

   /**
    * Acquire the local migration lock.
    */
   public function acquire (): bool
   {
      $dir = dirname($this->file);

      if (is_dir($dir) === false) {
         mkdir($dir, 0775, true);
      }

      $guard = @fopen("{$this->file}.guard", 'c');
      if ($guard === false) {
         return false;
      }

      $guarded = false;

      try {
         if (flock($guard, LOCK_EX | LOCK_NB) === false) {
            return false;
         }
         $guarded = true;

         $handle = @fopen($this->file, 'x');
         if ($handle === false) {
            if ($this->reclaim() === false) {
               return false;
            }

            $handle = @fopen($this->file, 'x');
            if ($handle === false) {
               return false;
            }
         }

         $pid = getmypid();
         $time = time();
         fwrite($handle, "pid={$pid}\ntime={$time}\n");
         fclose($handle);
      }
      finally {
         if ($guarded) {
            flock($guard, LOCK_UN);
         }
         fclose($guard);
      }

      $this->locked = true;

      return true;
   }

   /**
    * Check whether the local migration lock exists.
    */
   public function check (): bool
   {
      return is_file($this->file);
   }

   /**
    * Release the local migration lock.
    */
   public function release (): void
   {
      if ($this->locked && is_file($this->file)) {
         unlink($this->file);
      }

      $this->locked = false;
   }

   /**
    * Reclaim a lock whose writer process no longer exists.
    */
   private function reclaim (): bool
   {
      if (is_file($this->file) === false) {
         return false;
      }

      $contents = file_get_contents($this->file);
      if ($contents === false) {
         return false;
      }

      if (preg_match('/^pid=(\d+)$/m', $contents, $matches) !== 1) {
         return false;
      }

      $pid = (int) $matches[1];
      if ($this->probe($pid)) {
         return false;
      }

      return unlink($this->file);
   }

   /**
    * Probe if a lock writer process is still alive.
    */
   private function probe (int $pid): bool
   {
      if ($pid <= 0) {
         return false;
      }

      if (is_dir("/proc/{$pid}")) {
         return true;
      }

      if (posix_kill($pid, 0)) {
         return true;
      }

      return posix_get_last_error() === 1;
   }
}
