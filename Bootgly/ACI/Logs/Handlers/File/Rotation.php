<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Logs\Handlers\File;


use function ceil;
use function clearstatcache;
use function date;
use function floor;
use function hrtime;
use function lstat;
use function microtime;
use function rename;
use function unlink;
use function usleep;


class Rotation
{
   /**
    * The first moments of a day, in seconds, in which a file the filesystem
    * stamped with its coarse clock may still read as the day before.
    */
   private const float SETTLE = 0.05;

   // * Config
   public int $size;
   public bool $daily;
   public int $keep;

   // * Metadata
   /**
    * The current time, in seconds — what `check()` reads today from. A
    * subclass may override it only with a `get` hook: a plain redeclaration
    * keeps this hook.
    */
   protected float $now {
      get => microtime(true);
   }


   /**
    * File rotation policy: rotates on size cap OR day change, whichever comes first.
    *
    * @param int $size Size cap in bytes that triggers rotation (0 disables size rotation). Default 10MB.
    * @param bool $daily Rotate when the file's last-modified day differs from today.
    * @param int $keep Number of rotated archives to retain.
    */
   public function __construct (int $size = 10485760, bool $daily = true, int $keep = 7)
   {
      // * Config
      $this->size = $size;
      $this->daily = $daily;
      $this->keep = $keep;
   }

   /**
    * Check whether the file is due for rotation.
    *
    * Due only when the name is a regular, non-empty file that reached the
    * size cap or was last modified on another day. The name is never
    * followed: a link, a directory or an absent name is never due.
    *
    * This is the policy: a custom rotation overrides it, and `rotate()`
    * acts only when it answers true.
    *
    * In the first 50 ms of a day it may wait out the rest of them before
    * answering that a file of the day before is due (see below).
    *
    * @param string $path The active log file path.
    * @return bool
    */
   public function check (string $path): bool
   {
      // ! Fresh metadata: a writer that just waited for the lock must see what
      //   the process it waited for left behind
      clearstatcache();
      $named = @lstat($path);

      // ? Only a regular file with something in it is ever archived
      if ($named === false || ((int) $named['mode'] & 0170000) !== 0100000 || (int) $named['size'] === 0) {
         return false;
      }

      // ! The size cap, and the day the file was last written against today
      $full = $this->size > 0 && (int) $named['size'] >= $this->size;
      if ($this->daily === false) {
         return $full;
      }
      $now = $this->now;
      if (date('Y-m-d', (int) $named['mtime']) === date('Y-m-d', (int) $now)) {
         return $full;
      }

      // ? The first moments of a day: the filesystem stamps a write with a
      //   coarse clock that lags this one by up to a tick, so a file created
      //   right after midnight still reads as the day before — and would be
      //   rotated again, dropping an archive each time. Those moments are
      //   waited out before the day's rotation — under the caller's lock, so
      //   nobody writes meanwhile — and the file it leaves is stamped today.
      //   The day changed within them when the date SETTLE ago was another
      //   (a midnight a clock change repeats is not a new day); the wait runs
      //   on the monotonic clock and resumes after a signal.
      if (date('Y-m-d', (int) floor($now - self::SETTLE)) !== date('Y-m-d', (int) $now)) {
         $until = hrtime(true) + (int) ceil((floor($now) + self::SETTLE - $now) * 1_000_000_000);
         while (($left = $until - hrtime(true)) > 0) {
            usleep((int) ceil($left / 1_000));
         }
      }

      // :
      return true;
   }

   /**
    * Rotate the file when the size cap is reached or the day has changed.
    *
    * Archives are numbered `path.1` … `path.{keep}`, newest first. The first
    * free number is filled, so an archive is dropped (the oldest) only when
    * every number is taken; `keep` below 1 keeps no archive at all. The
    * rotation stops at the first step that fails and never raises: the chain
    * stays as it was below that step, so a retry drops nothing twice.
    *
    * The File handler calls it holding `LOCK_EX` on the inode `$path` names,
    * which serializes every process writing the same file. A direct caller
    * does the same: it holds `flock(LOCK_EX)` on the active file, checks that
    * `$path` still names the inode it locked, and rotates under that lock.
    * Nothing in here may lock or log to `$path`.
    *
    * @param string $path The active log file path.
    */
   public function rotate (string $path): void
   {
      // ? Nothing due
      if ($this->check($path) === false) {
         return;
      }

      // ? No archive kept: the active file goes
      if ($this->keep < 1) {
         @unlink($path);
         return;
      }

      // ! The first free number, or the oldest dropped to free it
      $free = 0;
      for ($index = 1; $index <= $this->keep; $index++) {
         if (@lstat("$path.$index") === false) {
            $free = $index;
            break;
         }
      }
      if ($free === 0) {
         if (@unlink("$path.$this->keep") === false) {
            return;
         }
         $free = $this->keep;
      }

      // @ Shift the archives below it up: path.{n} -> path.{n+1}
      for ($index = $free - 1; $index >= 1; $index--) {
         if (@rename("$path.$index", "$path." . ($index + 1)) === false) {
            return;
         }
      }

      // @ Move the active file to path.1
      @rename($path, "$path.1");
   }
}
