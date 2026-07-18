<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;


use const BOOTGLY_STORAGE_DIR;
use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;
use function chmod;
use function clearstatcache;
use function dirname;
use function fclose;
use function fflush;
use function filemtime;
use function filesize;
use function flock;
use function fopen;
use function fread;
use function fstat;
use function ftruncate;
use function fwrite;
use function getmypid;
use function hash;
use function hash_equals;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function is_string;
use function lchgrp;
use function lchown;
use function lstat;
use function max;
use function pack;
use function posix_getegid;
use function posix_geteuid;
use function posix_getgrnam;
use function posix_getpwnam;
use function rewind;
use function scandir;
use function strlen;
use function substr;
use function time;
use function umask;
use function unlink;
use function unpack;
use Throwable;


/**
 * Cross-worker aggregate counter of bytes currently held in the
 *   download temp directory (`storage/temp/files/downloaded/`).
 *   Closes the per-file × N-workers blowup that lets a coordinated
 *   client fill the disk while every individual download still
 *   respects `$maxFileSize`.
 *
 * From the server's POV, multipart file parts are *downloaded* from
 *   the client (the client uploads, the server downloads). This class
 *   accounts for those server-side temp files.
 *
 * One runtime-owned regular file stores an integrity-checked counter record
 *   and provides the advisory `flock` used by every read-modify-write critical
 *   section. Each process opens its own descriptor, including recovered
 *   workers and a re-executed demoted master.
 */
final class Downloads
{
   private const int RECORD_SIZE = 40;

   // * Config
   /**
    * Hard ceiling, in bytes, on the *aggregate* size of all in-flight
    *   downloads across every worker. Default 8 GiB.
    */
   public static int $maxBytesOnDisk = 8 * 1024 * 1024 * 1024;
   /**
    * Age (seconds) past which an on-disk temp file is treated as orphaned
    *   by a crashed worker and swept (audit F-10). Must exceed the
    *   `Decoder_Downloading` 60 s download deadline so a live in-flight
    *   upload is never deleted; 2× that gives a safe margin.
    */
   public const int ORPHAN_TTL = 120;

   // * Data
   /** @var resource|null */
   private static mixed $counter = null;
   private static string $counterfile = '';
   private static int $device = 0;
   private static int $inode = 0;
   private static int $PID = 0;
   /** @var array<string,int> Per-worker map of tmp_name → bytes reserved on the aggregate counter. */
   private static array $tracked = [];


   /**
    * Idempotent. The master creates a stable controller inode inside the
    *   protected process-state directory and hands that inode to the runtime
    *   identity before workers fork. Each process reopens it on first use.
    */
   public static function init (
      null|string $path = null,
      null|string $user = null,
      null|string $group = null,
   ): bool
   {
      // ?:
      if (self::$counterfile !== '') {
         if (self::bind() === false) {
            return false;
         }

         $counter = self::$counter;
         if (! is_resource($counter)) {
            return false;
         }

         try {
            $locked = @flock($counter, LOCK_EX);
         }
         catch (Throwable) {
            $locked = false;
         }
         if ($locked === false) {
            return false;
         }

         $readable = false;
         $unlocked = false;
         try {
            $readable = self::read($counter) !== null;
         }
         finally {
            try { $unlocked = @flock($counter, LOCK_UN); } catch (Throwable) {}
         }

         return $readable && $unlocked;
      }

      $PID = getmypid();
      if ($PID === false || $path === null || $path === '') {
         return false;
      }

      $directory = dirname($path);
      $directoryMetadata = @lstat($directory);
      if (
         is_link($directory)
         || ! is_dir($directory)
         || $directoryMetadata === false
         || (($directoryMetadata['mode'] & 0170000) !== 0040000)
         || (($directoryMetadata['mode'] & 0022) !== 0)
      ) {
         return false;
      }

      self::$counterfile = $path;
      $counter = self::open('c+b');
      if ($counter === false) {
         self::$counterfile = '';
         return false;
      }

      try {
         $locked = @flock($counter, LOCK_EX);
      }
      catch (Throwable) {
         $locked = false;
      }

      if ($locked === false) {
         try { @fclose($counter); } catch (Throwable) {}
         self::$counterfile = '';
         return false;
      }

      // @ Keep the exclusive lock from initialization through ownership/mode
      //   handoff. The inode is born 0600 under umask(0077), so no less-trusted
      //   UID can retain a writable descriptor before the final validation.
      $unlocked = false;
      try {
         $initialized = self::write($counter, 0);
         $granted = $initialized
            && self::grant($counter, self::$counterfile, $user, $group, 0600);
      }
      finally {
         try { $unlocked = @flock($counter, LOCK_UN); } catch (Throwable) {}
      }

      if (
         $initialized === false
         || $granted === false
         || $unlocked === false
      ) {
         try { @fclose($counter); } catch (Throwable) {}
         self::$counterfile = '';
         return false;
      }

      self::$counter = $counter;
      $metadata = @fstat($counter);
      if ($metadata === false) {
         try { @fclose($counter); } catch (Throwable) {}
         self::$counter = null;
         self::$counterfile = '';
         return false;
      }
      self::$device = (int) $metadata['dev'];
      self::$inode = (int) $metadata['ino'];
      self::$PID = $PID;

      return true;
   }

   /**
    * Give each process an independently opened counter/lock descriptor.
    * Linux flock locks are associated with the open file description, so
    * workers must not synchronize through the descriptor inherited from
    * their master: that shared description is treated as one lock owner.
    */
   private static function bind (): bool
   {
      $PID = getmypid();
      if ($PID === false) {
         return false;
      }
      if (self::$PID === $PID && is_resource(self::$counter)) {
         return true;
      }
      if (self::$counterfile === '') {
         return false;
      }

      $counter = self::open('r+b');
      if ($counter === false) {
         return false;
      }

      $inherited = self::$counter;
      self::$counter = $counter;
      self::$PID = $PID;

      if (is_resource($inherited)) {
         try { @fclose($inherited); } catch (Throwable) {}
      }

      return true;
   }

   /** @return resource|false */
   private static function open (string $mode): mixed
   {
      $path = self::$counterfile;
      if ($path === '' || is_link($path)) {
         return false;
      }

      clearstatcache(true, $path);
      $before = @lstat($path);
      if ($before !== false) {
         if (
            (($before['mode'] & 0170000) !== 0100000)
            || (($before['mode'] & 0777) !== 0600)
         ) {
            return false;
         }
      }

      $previousMask = umask(0077);
      try {
         // ! The initial master is the sole valid creator under the acquired
         //   service-state lock. Exclusive creation avoids following a name
         //   that appeared after lstat(); recovered/reloaded processes open
         //   only the already-validated stable inode.
         $counter = $before === false && $mode === 'c+b'
            ? @fopen($path, 'x+b')
            : @fopen($path, $mode);
      }
      catch (Throwable) {
         $counter = false;
      }
      finally {
         umask($previousMask);
      }
      if ($counter === false) {
         return false;
      }

      $opened = @fstat($counter);
      clearstatcache(true, $path);
      $current = @lstat($path);
      if (
         $opened === false
         || $current === false
         || (($current['mode'] & 0170000) !== 0100000)
         || $opened['dev'] !== $current['dev']
         || $opened['ino'] !== $current['ino']
         || (($opened['mode'] & 0777) !== 0600)
         || (($current['mode'] & 0777) !== 0600)
         || (self::$device !== 0 && (int) $opened['dev'] !== self::$device)
         || (self::$inode !== 0 && (int) $opened['ino'] !== self::$inode)
      ) {
         try { @fclose($counter); } catch (Throwable) {}
         return false;
      }

      return $counter;
   }

   private static function grant (
      mixed $counter,
      string $path,
      null|string $user,
      null|string $group,
      null|int $mode = null,
   ): bool {
      if (! is_resource($counter) || is_link($path)) {
         return false;
      }
      $openedBefore = @fstat($counter);
      $pathBefore = @lstat($path);
      if (
         $openedBefore === false
         || $pathBefore === false
         || (($openedBefore['mode'] & 0170000) !== 0100000)
         || (($pathBefore['mode'] & 0170000) !== 0100000)
         || $openedBefore['dev'] !== $pathBefore['dev']
         || $openedBefore['ino'] !== $pathBefore['ino']
      ) {
         return false;
      }

      $UID = posix_geteuid();
      $GID = posix_getegid();
      if ($UID === 0) {
         if ($user !== null) {
            $userInfo = posix_getpwnam($user);
            if ($userInfo === false) {
               return false;
            }
            $UID = (int) $userInfo['uid'];
            $GID = (int) $userInfo['gid'];

            if ($group !== null) {
               $groupInfo = posix_getgrnam($group);
               if ($groupInfo === false) {
                  return false;
               }
               $GID = (int) $groupInfo['gid'];
            }
         }

         if (@lchown($path, $UID) === false || @lchgrp($path, $GID) === false) {
            return false;
         }
      }

      // ! PHP exposes no descriptor-based chown/chmod API. The State-owned
      //   parent rejects symlinks and group/world write access; revalidate the
      //   pathname against the still-locked descriptor before chmod and again
      //   afterward so accidental replacement fails the boot closed.
      $middle = @lstat($path);
      if (
         $middle === false
         || (($middle['mode'] & 0170000) !== 0100000)
         || $openedBefore['dev'] !== $middle['dev']
         || $openedBefore['ino'] !== $middle['ino']
      ) {
         return false;
      }
      if ($mode !== null && @chmod($path, $mode) === false) {
         return false;
      }

      $openedAfter = @fstat($counter);
      $pathAfter = @lstat($path);

      return $openedAfter !== false
         && $pathAfter !== false
         && (($openedAfter['mode'] & 0170000) === 0100000)
         && (($pathAfter['mode'] & 0170000) === 0100000)
         && $openedBefore['dev'] === $openedAfter['dev']
         && $openedBefore['ino'] === $openedAfter['ino']
         && $openedAfter['dev'] === $pathAfter['dev']
         && $openedAfter['ino'] === $pathAfter['ino']
         && (($openedAfter['mode'] & 0777) === ($mode ?? ($openedAfter['mode'] & 0777)))
         && (($pathAfter['mode'] & 0777) === ($mode ?? ($pathAfter['mode'] & 0777)))
         && (int) $openedAfter['uid'] === $UID
         && (int) $openedAfter['gid'] === $GID
         && (int) $pathAfter['uid'] === $UID
         && (int) $pathAfter['gid'] === $GID;
   }

   /**
    * Read one complete unsigned 64-bit counter value. Null is a controller
    * failure, never a synthetic zero: callers must not undercount on errors.
    */
   private static function read (mixed $counter): null|int
   {
      if (! is_resource($counter)) {
         return null;
      }

      // !?:
      try {
         $metadata = @fstat($counter);
         if (
            $metadata === false
            || $metadata['size'] !== self::RECORD_SIZE
            || @rewind($counter) === false
         ) {
            return null;
         }
         $record = @fread($counter, self::RECORD_SIZE);
      }
      catch (Throwable) {
         return null;
      }
      if (! is_string($record) || strlen($record) !== self::RECORD_SIZE) {
         return null;
      }

      $raw = substr($record, 0, 8);
      $digest = substr($record, 8);
      if (! hash_equals(hash('sha256', $raw, true), $digest)) {
         return null;
      }

      // !?:
      $u = @unpack('P', $raw);
      if ($u === false || !isset($u[1])) {
         return null;
      }

      $value = (int) $u[1];

      return $value >= 0 ? $value : null;
   }

   /**
    * Persist one complete unsigned 64-bit counter value.
    */
   private static function write (mixed $counter, int $value): bool
   {
      if (! is_resource($counter)) {
         return false;
      }

      try {
         if (@rewind($counter) === false || @ftruncate($counter, 0) === false) {
            return false;
         }

         $raw = pack('P', $value);
         $record = $raw . hash('sha256', $raw, true);

         return @fwrite($counter, $record) === self::RECORD_SIZE
            && @fflush($counter)
            && self::read($counter) === $value;
      }
      catch (Throwable) {
         return false;
      }
   }

   /**
    * Atomically reserve `$bytes` against the aggregate cap.
    *   Returns false if the reservation would exceed the cap (caller
    *   should reject the download chunk with UPLOAD_ERR_CANT_WRITE).
    *   Returns true if reserved (caller MUST call release($bytes)
    *   when the file is unlinked or on rollback).
    *   Returns false when the shared controller cannot prove and persist
    *   the reservation. Infrastructure failure is fail-closed.
    */
   public static function reserve (int $bytes): bool
   {
      // ?:
      if ($bytes <= 0) {
         return true;
      }

      // ?:
      if (self::bind() === false) {
         return false;
      }
      $counter = self::$counter;
      if (! is_resource($counter)) {
         return false;
      }

      // !?:
      try {
         $locked = @flock($counter, LOCK_EX);
      }
      catch (Throwable) {
         $locked = false;
      }
      if ($locked === false) {
         return false;
      }

      // @ The reservation is successful only if both the exact counter write
      //   and lock release succeed. A committed reservation followed by a
      //   failed unlock is conservatively rejected and may only overcount.
      $reserved = false;
      $unlocked = false;
      try {
         $cur = self::read($counter);
         $maxBytesOnDisk = self::$maxBytesOnDisk;

         // ! A failed/invalid read, a counter already beyond the ceiling,
         //   and addition overflow all reject before any upload byte is written.
         if (
            $cur === null
            || $cur > $maxBytesOnDisk
            || $bytes > $maxBytesOnDisk - $cur
         ) {
            $reserved = false;
         }
         else {
            $reserved = self::write($counter, $cur + $bytes);
         }
      }
      finally {
         try { $unlocked = @flock($counter, LOCK_UN); } catch (Throwable) {}
      }

      return $reserved && $unlocked;
   }

   /**
    * Atomically release `$bytes` previously reserved. Saturates at 0
    *   to defend against accounting drift from reject/crash paths.
    */
   public static function release (int $bytes): void
   {
      // ?:
      if ($bytes <= 0) {
         return;
      }

      // ?:
      if (self::bind() === false) {
         return;
      }
      $counter = self::$counter;
      if (! is_resource($counter)) {
         return;
      }

      // !?:
      try {
         $locked = @flock($counter, LOCK_EX);
      }
      catch (Throwable) {
         $locked = false;
      }
      if ($locked === false) {
         return;
      }

      // @
      try {
         $cur = self::read($counter);
         if ($cur === null) {
            return;
         }

         $new = max(0, $cur - $bytes);
         self::write($counter, $new);
      }
      finally {
         try { @flock($counter, LOCK_UN); } catch (Throwable) {}
      }
   }

   /**
    * Shared-lock read of the current aggregate. Used for diagnostics and the
    *   security test harness; mutations use an exclusive lock.
    */
   public static function peek (): int
   {
      if (self::bind() === false) {
         return 0;
      }
      $counter = self::$counter;
      if (! is_resource($counter)) {
         return 0;
      }

      try {
         $locked = @flock($counter, LOCK_SH);
      }
      catch (Throwable) {
         $locked = false;
      }
      if ($locked === false) {
         return 0;
      }

      try {
         return self::read($counter) ?? 0;
      }
      finally {
         try { @flock($counter, LOCK_UN); } catch (Throwable) {}
      }
   }

   /**
    * Associate `$bytes` already-reserved bytes with a tmp file path so
    *   they can be released atomically when the file is unlinked. Used
    *   by Decoder_Downloading after a successful reserve(). Idempotent
    *   per tmp_name (additive).
    */
   public static function track (string $tmpName, int $bytes): void
   {
      // ?:
      if ($tmpName === '' || $bytes <= 0) {
         return;
      }

      self::$tracked[$tmpName] = (self::$tracked[$tmpName] ?? 0) + $bytes;
   }

   /**
    * Decrement the tracked total for `$tmpName` (without touching the
    *   shared aggregate counter). Used by Decoder_Downloading rollback
    *   paths that have already called release() to undo a reserve().
    */
   public static function untrack (string $tmpName, int $bytes): void
   {
      // ?:
      if ($tmpName === '' || $bytes <= 0) {
         return;
      }
      if (! isset(self::$tracked[$tmpName])) {
         return;
      }

      $remain = self::$tracked[$tmpName] - $bytes;

      if ($remain <= 0) {
         unset(self::$tracked[$tmpName]);
      }
      else {
         self::$tracked[$tmpName] = $remain;
      }
   }

   /**
    * Release whatever bytes were tracked for `$tmpName` and forget it.
    *   No-op if the tmp name was never tracked.
    */
   public static function discard (string $tmpName): void
   {
      // ?:
      if (! isset(self::$tracked[$tmpName])) {
         return;
      }

      $bytes = self::$tracked[$tmpName];
      unset(self::$tracked[$tmpName]);
      self::release($bytes);
   }

   /**
    * Recompute the aggregate counter from the bytes actually on disk
    *   (audit F-10). The shared total is a *cache* of the download directory
    *   size, not the source of truth: a worker that dies mid-request leaves
    *   its reservation stranded on the counter (its in-memory `$tracked`
    *   map dies with it), permanently shrinking the budget until the master
    *   restarts. Re-running this from each (re)spawned worker heals that
    *   drift. Lock-held so it serializes with `reserve()`/`release()`.
    */
   public static function reconcile (): void
   {
      // ?:
      if (self::bind() === false) {
         return;
      }
      $counter = self::$counter;
      if (! is_resource($counter)) {
         return;
      }

      // !?:
      try {
         $locked = @flock($counter, LOCK_EX);
      }
      catch (Throwable) {
         $locked = false;
      }
      if ($locked === false) {
         return;
      }

      // @
      try {
         self::write($counter, self::measure());
      }
      finally {
         try { @flock($counter, LOCK_UN); } catch (Throwable) {}
      }
   }

   /**
    * Delete temp files orphaned by a crashed worker (audit F-10). A file
    *   whose mtime is older than `$minAge` seconds cannot belong to a live
    *   in-flight download — `Decoder_Downloading` aborts a stalled download
    *   at its 60 s deadline — so a `$minAge` of `ORPHAN_TTL` (2× that)
    *   never touches an active upload. `$minAge = 0` deletes everything and
    *   is master-boot-only (no worker is in-flight before the first fork).
    */
   public static function sweep (int $minAge = 0): void
   {
      $dir = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';

      // ?:
      if (! is_dir($dir)) {
         return;
      }

      clearstatcache();

      $cutoff = time() - $minAge;
      $entries = @scandir($dir);
      if ($entries === false) {
         return;
      }

      // @@
      foreach ($entries as $entry) {
         // ? Skip `.`/`..` and any dotfile placeholder (e.g. `.gitkeep`) —
         //   temp uploads are `tempnam()`-named and never start with a dot.
         if ($entry[0] === '.') {
            continue;
         }

         $path = $dir . $entry;
         if (! is_file($path)) {
            continue;
         }

         if ((int) @filemtime($path) <= $cutoff) {
            try { @unlink($path); } catch (Throwable) {}
         }
      }
   }

   /**
    * Sum the bytes currently held in the download temp directory. Source of
    *   truth for `reconcile()`.
    */
   private static function measure (): int
   {
      $dir = BOOTGLY_STORAGE_DIR . 'temp/files/downloaded/';

      // ?:
      if (! is_dir($dir)) {
         return 0;
      }

      clearstatcache();

      $entries = @scandir($dir);
      if ($entries === false) {
         return 0;
      }

      // @@
      $total = 0;
      foreach ($entries as $entry) {
         // ? Skip `.`/`..` and any dotfile placeholder (e.g. `.gitkeep`) —
         //   temp uploads are `tempnam()`-named and never start with a dot.
         if ($entry[0] === '.') {
            continue;
         }

         $path = $dir . $entry;
         if (is_file($path)) {
            $total += (int) @filesize($path);
         }
      }

      return $total;
   }

   /**
    * Process-local teardown: close this descriptor without resetting or
    *   unlinking the shared inode. The HTTP master calls this before the base
    *   server drains its workers, so mutating the record here would lower the
    *   aggregate while uploads may still be completing. The next pre-fork
    *   `init()` owns the authoritative reset.
    */
   public static function destroy (): void
   {
      $counter = self::$counter;
      if (is_resource($counter)) {
         try { @fclose($counter); } catch (Throwable) {}
      }
      self::$counter = null;
      self::$counterfile = '';
      self::$device = 0;
      self::$inode = 0;
      self::$PID = 0;
      self::$tracked = [];
   }
}
