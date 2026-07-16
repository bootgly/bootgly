<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use function basename;
use function bin2hex;
use function filemtime;
use function fileperms;
use function function_exists;
use function getmypid;
use function glob;
use function is_dir;
use function is_link;
use function mkdir;
use function posix_kill;
use function preg_match;
use function random_bytes;
use function rmdir;
use function rtrim;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function time;
use function unlink;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;


/**
 * Per-run test temporaries with automatic orphan reaping.
 *
 * Interrupted test runs (timeouts, kills, crashes) never execute their
 * teardown, so ad-hoc `sys_get_temp_dir()` files accumulate for the machine
 * lifetime — a real workstation measured 23,000+ orphans totaling 34 GiB,
 * inflating the WSL2 VHD and degrading the host over time.
 *
 * reserve() creates every temporary under one owned root whose entries are
 * self-describing (`<pid>-<name>-<nonce>`), so sweep() can safely reap any
 * entry whose owning process is gone (or whose age exceeds the PID-reuse
 * guard). Legacy pre-Temporaries patterns are reaped by an explicit curated
 * prefix list and an age floor only — live production state (for example
 * `bootgly-queues`, `bootgly-storage`, `bootgly-tls-*`, `bootgly-reload-*`)
 * is never matched.
 */
class Temporaries
{
   // * Config
   /**
    * Age (seconds) past which an owned entry is reaped even if a process
    * with its recorded PID is alive — the PID may have been reused.
    */
   public const int OWNED_TTL = 86_400;
   /**
    * Age (seconds) a legacy-pattern entry must reach before it is reaped.
    * High enough that any legitimately running suite has finished.
    */
   public const int LEGACY_TTL = 21_600;
   /**
    * Directory (under the system temp dir) owning every reserve()d entry.
    */
   public const string ROOT = 'bootgly-tests';
   /**
    * Temp-name prefixes used by pre-Temporaries test suites, reaped by age.
    *
    * Curated: every entry MUST be a test-only pattern. Production surfaces —
    * `bootgly-queues`, `bootgly-storage` (both exact, no trailing hyphen),
    * `bootgly-tls-*`, `bootgly-reload-*` — must never be listed.
    */
   public const array LEGACY = [
      'bootgly-acme-account-link-',
      'bootgly-acme-bootstrap-',
      'bootgly-acme-challenge-e2e-',
      'bootgly-acme-challenge-victim-',
      'bootgly-acme-csr-',
      'bootgly-acme-pebble-e2e-',
      'bootgly-acme-swap-e2e-',
      'bootgly-auth-e2e-',
      'bootgly-cache-events-',
      'bootgly-cache-test-',
      'bootgly-daemon-topology-',
      'bootgly-mail-queue-',
      'bootgly-mail-service-',
      'bootgly-qloop-',
      'bootgly-qmsg-',
      'bootgly-qsec-',
      'bootgly-queue-',
      'bootgly-ratelimit-',
      'bootgly-rbac-test-',
      'bootgly-round2-',
      'bootgly-session-test-',
      'bootgly-state-',
      'bootgly-storage-',
      'bootgly-test-',
      'bootgly-verbatim-',
   ];


   /**
    * Reserve one fresh temporary directory for the calling test process.
    *
    * The entry lives under the owned root as `<pid>-<name>-<nonce>` so a
    * later sweep() can prove ownership. Returns the absolute path without a
    * trailing separator.
    */
   public static function reserve (string $name): string
   {
      // ? The name lands in a filesystem entry sweep() must parse back
      if (preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $name) !== 1) {
         throw new InvalidArgumentException(
            'Temporary names must be lowercase alphanumerics/hyphens.'
         );
      }

      // !
      $root = rtrim(sys_get_temp_dir(), '/') . '/' . self::ROOT;
      if (is_dir($root) === false && @mkdir($root, 0o777, true) === false && is_dir($root) === false) {
         throw new RuntimeException("Cannot create the test temporaries root: {$root}");
      }

      // @
      $path = "{$root}/" . getmypid() . "-{$name}-" . bin2hex(random_bytes(4));
      if (@mkdir($path, 0o700) === false) {
         throw new RuntimeException("Cannot reserve the test temporary: {$path}");
      }

      // :
      return $path;
   }

   /**
    * Reap orphaned test temporaries.
    *
    * Owned entries are removed when their recorded PID is no longer alive or
    * their age exceeds the PID-reuse guard. Legacy-pattern entries are
    * removed by age alone. Never throws — reaping is best-effort by design.
    *
    * @param null|string $temp Temp base to sweep — the system temp dir by
    *        default. Injectable so tests exercise the reaper against a
    *        synthetic base instead of the machine's real scratch space.
    *
    * @return int Number of top-level entries removed.
    */
   public static function sweep (null|string $temp = null): int
   {
      $removed = 0;
      $now = time();
      $temp = rtrim($temp ?? sys_get_temp_dir(), '/');

      // @ Owned root — `<pid>-<name>-<nonce>` entries
      $entries = glob("{$temp}/" . self::ROOT . '/*') ?: [];
      foreach ($entries as $entry) {
         $base = basename($entry);
         $separator = strpos($base, '-');
         $PID = $separator === false ? 0 : (int) substr($base, 0, $separator);

         $alive = $PID > 0
            && function_exists('posix_kill')
            && @posix_kill($PID, 0) === true;
         $age = $now - (int) (@filemtime($entry) ?: $now);

         if ($alive === false || $age > self::OWNED_TTL) {
            $removed += (int) self::purge($entry);
         }
      }

      // @ Legacy patterns — age floor only (no ownership metadata)
      foreach (self::LEGACY as $prefix) {
         $entries = glob("{$temp}/{$prefix}*") ?: [];
         foreach ($entries as $entry) {
            $age = $now - (int) (@filemtime($entry) ?: $now);
            if ($age > self::LEGACY_TTL) {
               $removed += (int) self::purge($entry);
            }
         }
      }

      // :
      return $removed;
   }

   /**
    * Remove one entry (file, link or directory tree) without following links.
    */
   private static function purge (string $path): bool
   {
      try {
         if (is_link($path) || is_dir($path) === false) {
            return @unlink($path);
         }

         $Entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
         );

         foreach ($Entries as $Entry) {
            /** @var SplFileInfo $Entry */
            $pathname = $Entry->getPathname();

            if ($Entry->isLink() || $Entry->isDir() === false) {
               @unlink($pathname);
            }
            else {
               @rmdir($pathname);
            }
         }

         return @rmdir($path);
      }
      catch (Throwable) {
         // ? Permission/race issues on shared /tmp — leave the entry behind
         return false;
      }
   }
}
