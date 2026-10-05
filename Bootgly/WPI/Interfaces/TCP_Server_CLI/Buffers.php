<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use function intdiv;
use function max;

use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;


/**
 * Worker-wide accountant for transport-owned in-memory bytes.
 *
 * One token belongs to one independent retention owner (a TCP Package or an
 * HTTP/2 Stream). `reserve()` is absolute: the owner states the footprint it
 * is about to retain and the ledger moves only by the difference. Workers use
 * one synchronous event loop, so admission and accounting cannot race.
 */
final class Buffers
{
   /** Zend MM small-allocation bin sizes, in bytes. */
   private const array BINS = [
      8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256,
      320, 384, 448, 512, 640, 768, 896, 1_024, 1_280, 1_536, 1_792, 2_048, 2_560, 3_072,
   ];

   /** Authoritative total; the Server property is its public diagnostic. */
   private static int $total = 0;
   /** Bytes currently reserved by this owner. */
   public protected(set) int $retained = 0;


   /**
    * Hold exactly `$bytes` for this owner.
    *
    * Growth that does not fit leaves the previous reservation untouched.
    * Shrinkage always succeeds, including after an operator lowers the cap
    * below the amount already live.
    */
   public function reserve (int $bytes): bool
   {
      // !
      $wanted = max(0, $bytes);
      $growth = $wanted - $this->retained;

      // ? Subtraction avoids overflowing while testing the projected total.
      $limit = max(0, Server::$maxWorkerPendingBytes);
      if ($growth > 0 && $growth > $limit - self::$total) {
         return false;
      }

      // @
      $this->retained = $wanted;
      self::$total = max(0, self::$total + $growth);
      Server::$pendingBytes = self::$total;

      // :
      return true;
   }

   /**
    * The memory PHP's allocator spends to keep a string of `$bytes` bytes —
    * what an owner of held strings reserves, rather than their length.
    *
    * A small string takes its bin slot; a large one whole pages, of which a
    * 2 MiB chunk packs as many strings as fit in its 511 usable pages (so a
    * string needing more than 255 pages, about 1 MiB, takes a whole chunk); a
    * huge one its own page-aligned mapping. `memory_limit` counts these
    * footprints, so a ledger of raw lengths would admit about twice what fits.
    */
   public static function weigh (int $bytes): int
   {
      // ?
      if ($bytes <= 0) {
         return 0;
      }

      // ! The zend_string header (24 bytes) and the trailing NUL, 8-aligned
      $size = ($bytes + 25 + 7) & ~7;

      // ? Small: the smallest bin slot that fits
      if ($size <= 3_072) {
         foreach (self::BINS as $bin) {
            if ($bin >= $size) {
               return $bin;
            }
         }
      }
      // ? Huge: an own mapping of whole pages
      if ($size > 2_093_056) {
         return intdiv($size + 4_095, 4_096) * 4_096;
      }

      // @ Large: whole pages, as many per chunk as fit (511 usable pages) —
      //   each string pays its share of the 2 MiB chunk
      $pages = intdiv($size + 4_095, 4_096);
      $fit = intdiv(511, $pages);

      // :
      return intdiv(2_097_152 + $fit - 1, $fit);
   }

   /** Return this owner's complete reservation. Idempotent. */
   public function release (): void
   {
      // ?
      if ($this->retained === 0) {
         return;
      }

      // @
      self::$total = max(0, self::$total - $this->retained);
      $this->retained = 0;
      Server::$pendingBytes = self::$total;
   }

   /**
    * Start a freshly-forked worker ledger.
    *
    * The server calls this before Worker::Boot, while no worker-owned
    * connection can exist. Runtime callers must never reset live owners.
    */
   public static function reset (): void
   {
      self::$total = 0;
      Server::$pendingBytes = 0;
   }

   public function __destruct ()
   {
      $this->release();
   }
}
