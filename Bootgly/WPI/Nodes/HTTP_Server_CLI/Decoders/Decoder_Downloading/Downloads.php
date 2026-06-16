<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;


use const BOOTGLY_WORKING_BASE;
use const LOCK_EX;
use const LOCK_UN;
use function clearstatcache;
use function dirname;
use function extension_loaded;
use function fclose;
use function filemtime;
use function filesize;
use function flock;
use function fopen;
use function ftok;
use function is_dir;
use function is_file;
use function is_resource;
use function max;
use function mkdir;
use function pack;
use function scandir;
use function shmop_close;
use function shmop_delete;
use function shmop_open;
use function shmop_read;
use function shmop_write;
use function time;
use function touch;
use function unlink;
use function unpack;
use Shmop;
use Throwable;


/**
 * Cross-worker aggregate counter of bytes currently held in the
 *   download temp directory (`workdata/temp/files/downloaded/`).
 *   Closes the per-file × N-workers blowup that lets a coordinated
 *   client fill the disk while every individual download still
 *   respects `$maxFileSize`.
 *
 * From the server's POV, multipart file parts are *downloaded* from
 *   the client (the client uploads, the server downloads). This class
 *   accounts for those server-side temp files.
 *
 * System V shared memory via `shmop` for the
 *   8-byte counter, with an advisory `flock` lockfile for the
 *   read-modify-write critical section. Reads (`peek()`) are
 *   lock-free.
 */
final class Downloads
{
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
   private static null|Shmop $Shmop = null;
   /** @var resource|null */
   private static mixed $lock = null;
   private static bool $owner = false;
   /** @var array<string,int> Per-worker map of tmp_name → bytes reserved on the aggregate counter. */
   private static array $tracked = [];


   /**
    * Idempotent. Must be invoked from the master process before
    *   workers fork so the SHM segment + lockfile are inherited.
    */
   public static function init (): void
   {
      // ?:
      if (self::$Shmop !== null) {
         return;
      }
      if (! extension_loaded('shmop')) {
         return;
      }

      $base = BOOTGLY_WORKING_BASE . '/workdata/temp/';
      if (! is_dir($base)) {
         mkdir($base, 0700, true);
      }

      $anchor = $base . '.downloads.shm';
      $lockfile = $base . '.downloads.lock';

      if (! is_dir(dirname($anchor))) {
         mkdir(dirname($anchor), 0700, true);
      }
      touch($anchor);
      touch($lockfile);

      // !?:
      $key = ftok($anchor, 'B');
      if ($key === -1) {
         return;
      }

      try {
         $Shmop = @shmop_open($key, 'c', 0600, 8);
      }
      catch (Throwable) {
         $Shmop = false;
      }

      // ?:
      if ($Shmop === false) {
         return;
      }

      // !?:
      $lock = @fopen($lockfile, 'c+');
      if ($lock === false) {
         try { @shmop_close($Shmop); } catch (Throwable) {}

         return;
      }

      // @ Master initializes counter to 0 on first creation
      @shmop_write($Shmop, pack('P', 0), 0);

      self::$Shmop = $Shmop;
      self::$lock = $lock;
      self::$owner = true;
   }

   private static function read (Shmop $Shmop): int
   {
      // !?:
      $raw = @shmop_read($Shmop, 0, 8);
      if ($raw === '') {
         return 0;
      }

      // !?:
      $u = @unpack('P', $raw);
      if ($u === false || !isset($u[1])) {
         return 0;
      }

      return (int) $u[1];
   }

   /**
    * Atomically reserve `$bytes` against the aggregate cap.
    *   Returns false if the reservation would exceed the cap (caller
    *   should reject the download chunk with UPLOAD_ERR_CANT_WRITE).
    *   Returns true if reserved (caller MUST call release($bytes)
    *   when the file is unlinked or on rollback).
    *   Returns true (no-op) if SHM is unavailable — preserves
    *   per-process behaviour as a fail-open fallback when the
    *   `shmop` extension is missing.
    */
   public static function reserve (int $bytes): bool
   {
      // ?:
      if ($bytes <= 0) {
         return true;
      }

      $Shmop = self::$Shmop;
      $lock = self::$lock;

      // ?:
      if ($Shmop === null || $lock === null) {
         return true;
      }

      // !?:
      $locked = @flock($lock, LOCK_EX);
      if ($locked === false) {
         return true;
      }

      // @
      try {
         $cur = self::read($Shmop);
         $new = $cur + $bytes;

         if ($new > self::$maxBytesOnDisk) {
            return false;
         }

         @shmop_write($Shmop, pack('P', $new), 0);

         return true;
      }
      finally {
         @flock($lock, LOCK_UN);
      }
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

      $Shmop = self::$Shmop;
      $lock = self::$lock;

      // ?:
      if ($Shmop === null || $lock === null) {
         return;
      }

      // !?:
      $locked = @flock($lock, LOCK_EX);
      if ($locked === false) {
         return;
      }

      // @
      try {
         $cur = self::read($Shmop);
         $new = max(0, $cur - $bytes);
         @shmop_write($Shmop, pack('P', $new), 0);
      }
      finally {
         @flock($lock, LOCK_UN);
      }
   }

   /**
    * Lock-free read of the current aggregate. Used for diagnostics
    *   and the security test harness. Not safe for read-modify-write.
    */
   public static function peek (): int
   {
      // !?:
      $Shmop = self::$Shmop;
      if ($Shmop === null) {
         return 0;
      }

      return self::read($Shmop);
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
    *   (audit F-10). The SHM total is a *cache* of the download directory
    *   size, not the source of truth: a worker that dies mid-request leaves
    *   its reservation stranded on the counter (its in-memory `$tracked`
    *   map dies with it), permanently shrinking the budget until the master
    *   restarts. Re-running this from each (re)spawned worker heals that
    *   drift. Lock-held so it serializes with `reserve()`/`release()`.
    */
   public static function reconcile (): void
   {
      $Shmop = self::$Shmop;
      $lock = self::$lock;

      // ?:
      if ($Shmop === null || $lock === null) {
         return;
      }

      // !?:
      $locked = @flock($lock, LOCK_EX);
      if ($locked === false) {
         return;
      }

      // @
      try {
         @shmop_write($Shmop, pack('P', self::bytes()), 0);
      }
      finally {
         @flock($lock, LOCK_UN);
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
      $dir = BOOTGLY_WORKING_BASE . '/workdata/temp/files/downloaded/';

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
   private static function bytes (): int
   {
      $dir = BOOTGLY_WORKING_BASE . '/workdata/temp/files/downloaded/';

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
    * Master-only teardown: removes the SHM segment + lockfile.
    *   Safe to call from non-owner workers (no-op).
    */
   public static function destroy (): void
   {
      if (self::$lock !== null && is_resource(self::$lock)) {
         try { @fclose(self::$lock); } catch (Throwable) {}
      }
      self::$lock = null;

      // !?
      $Shmop = self::$Shmop;
      if ($Shmop !== null && self::$owner) {
         try { @shmop_delete($Shmop); } catch (Throwable) {}
         try { @shmop_close($Shmop); } catch (Throwable) {}
      }

      self::$Shmop = null;
      self::$owner = false;
      self::$tracked = [];

      $base = BOOTGLY_WORKING_BASE . '/workdata/temp/';
      clearstatcache();

      // @@
      foreach (['.downloads.lock', '.downloads.shm'] as $f) {
         $p = $base . $f;
         try { @unlink($p); } catch (Throwable) {}
      }
   }
}
