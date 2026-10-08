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


use const PHP_INT_MAX;
use function intdiv;
use function max;
use function min;

use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;


/**
 * Worker-wide accountant for the in-memory bytes a worker holds between
 * event-loop callbacks.
 *
 * One token belongs to one independent retention owner (a TCP Package, an
 * HTTP/2 Stream, an unfinished HTTP body, a WebSocket session's inbound hold,
 * the route cache). `reserve()` is absolute: the owner states the footprint it
 * is about to retain — the sum of `weigh()` over every string it holds, never
 * `weigh()` of their summed length — and the ledger moves only by the
 * difference. Workers use one synchronous event loop, so admission and
 * accounting cannot race.
 *
 * Every token draws on one budget, `TCP_Server_CLI::$maxWorkerPendingBytes`,
 * inside its share (`Shares`): Inbound holds stay within half of it and
 * Resident holds within a quarter, so Transport always keeps at least a
 * quarter.
 */
final class Buffers
{
   /** Zend MM small-allocation bin sizes, in bytes. */
   private const array BINS = [
      8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256,
      320, 384, 448, 512, 640, 768, 896, 1_024, 1_280, 1_536, 1_792, 2_048, 2_560, 3_072,
   ];

   // * Config
   /** The share of the worker budget this owner draws on. */
   public private(set) Shares $Share;

   // * Metadata
   /** Authoritative total; the Server property is its public diagnostic. */
   private static int $total = 0;
   /**
    * Footprint held by the owners of each share, by `Shares` value.
    *
    * @var array<int,int>
    */
   private static array $held = [0, 0, 0];
   /** Ledger generation: `reset()` starts a new one. */
   private static int $epoch = 0;
   /**
    * Bin slot of each small size already weighed, by 8-byte-aligned size.
    *
    * @var array<int,int>
    */
   private static array $slots = [];
   /** Bytes currently reserved by this owner. */
   public protected(set) int $retained = 0;
   /**
    * The most bytes this owner could hold right now: its reservation plus
    * the growth the worker budget and its share still admit.
    */
   public int $available {
      get {
         $retained = $this->stamp === self::$epoch ? $this->retained : 0;
         $limit = max(0, Server::$maxWorkerPendingBytes);
         $room = min(
            $limit - self::$total,
            $this->limit($limit) - self::$held[$this->Share->value]
         );

         return $retained + max(0, $room);
      }
   }
   /** Ledger generation `$retained` was reserved in. */
   private int $stamp;


   public function __construct (Shares $Share = Shares::Transport)
   {
      // * Config
      $this->Share = $Share;

      // * Metadata
      $this->stamp = self::$epoch;
   }

   /**
    * Hold exactly `$bytes` for this owner.
    *
    * Growth that does not fit the worker budget, or this owner's share of
    * it, leaves the previous reservation untouched. Shrinkage always
    * succeeds, including after an operator lowers the cap below the amount
    * already live.
    */
   public function reserve (int $bytes): bool
   {
      // ? A token reserved before the last reset() holds nothing in this ledger
      if ($this->stamp !== self::$epoch) {
         $this->stamp = self::$epoch;
         $this->retained = 0;
      }

      // !
      $wanted = max(0, $bytes);
      $growth = $wanted - $this->retained;
      $share = $this->Share->value;

      // ? Growth must fit the budget and this owner's share of it.
      //   Subtraction avoids overflowing while testing the projected totals.
      if ($growth > 0) {
         $limit = max(0, Server::$maxWorkerPendingBytes);
         if ($growth > $limit - self::$total) {
            return false;
         }

         if ($growth > $this->limit($limit) - self::$held[$share]) {
            return false;
         }
      }

      // @
      $this->retained = $wanted;
      self::$total = max(0, self::$total + $growth);
      self::$held[$share] = max(0, self::$held[$share] + $growth);
      Server::$pendingBytes = self::$total;

      // :
      return true;
   }

   /** Cap this owner's share of a worker budget of `$budget` bytes. */
   private function limit (int $budget): int
   {
      // :
      return match ($this->Share) {
         Shares::Transport => $budget,
         Shares::Inbound => intdiv($budget, 2),
         Shares::Resident => intdiv($budget, 4),
      };
   }

   /**
    * The memory PHP's allocator spends to keep a string of `$bytes` bytes —
    * what an owner of held strings reserves, rather than their length.
    *
    * A small string takes its bin slot; a large one whole pages, of which a
    * 2 MiB chunk packs as many strings as fit in its 511 usable pages — less
    * one: strings that grow side by side move out of each other's way and
    * leave holes their peers cannot reuse, so a string needing more than 170
    * pages (about 680 KiB) is charged a whole chunk; a huge one its own
    * page-aligned mapping. `memory_limit` counts these footprints, so a
    * ledger of raw lengths would admit about twice what fits.
    *
    * Weigh each held string on its own and sum the weights: the weight of a
    * summed length can be far lower than what the strings spend apart.
    */
   public static function weigh (int $bytes): int
   {
      // ?
      if ($bytes <= 0) {
         return 0;
      }
      // ? Past what a page-aligned mapping can express: saturate
      if ($bytes > PHP_INT_MAX - 4_128) {
         return PHP_INT_MAX;
      }

      // ? Small: the smallest bin slot that fits the zend_string header (24
      //   bytes) and the trailing NUL, 8-aligned — a padded string past 3,040
      //   bytes already takes a page
      if ($bytes <= 3_040) {
         $size = ($bytes + 25 + 7) & ~7;
         if (isSet(self::$slots[$size])) {
            return self::$slots[$size];
         }

         foreach (self::BINS as $bin) {
            if ($bin >= $size) {
               return self::$slots[$size] = $bin;
            }
         }
      }

      // ! Pages: a string built by repetition or padding takes an unaligned
      //   32-byte header instead (a few bytes more) — price the larger, so
      //   a string at a page or chunk boundary is never charged a class
      //   below what it spends.
      $size = $bytes + 32;

      // ? Huge: an own mapping of whole pages
      if ($size > 2_093_056) {
         return intdiv($size + 4_095, 4_096) * 4_096;
      }

      // @ Large: whole pages, as many per chunk as fit (511 usable pages)
      //   less one lost to fragmentation — each string pays its share of the
      //   2 MiB chunk
      $pages = intdiv($size + 4_095, 4_096);
      $fit = max(1, intdiv(511, $pages) - 1);

      // :
      return intdiv(2_097_152 + $fit - 1, $fit);
   }

   /**
    * The budget a worker can afford under a `memory_limit` of `$memory`
    * bytes: at most half of it, so the transient copies no ledger sees
    * (response builds, string growth, the application heap) keep the other
    * half. A non-positive `$memory` (no limit, `-1`) keeps the budget.
    */
   public static function fit (int $bytes, int $memory): int
   {
      // !
      $budget = max(0, $bytes);

      // ? No memory limit
      if ($memory <= 0) {
         return $budget;
      }

      // :
      return min($budget, intdiv($memory, 2));
   }

   /** Return this owner's complete reservation. Idempotent. */
   public function release (): void
   {
      // ? A token reserved before the last reset() holds nothing in this ledger
      if ($this->stamp !== self::$epoch) {
         $this->stamp = self::$epoch;
         $this->retained = 0;

         return;
      }
      if ($this->retained === 0) {
         return;
      }

      // @
      $share = $this->Share->value;
      self::$total = max(0, self::$total - $this->retained);
      self::$held[$share] = max(0, self::$held[$share] - $this->retained);
      $this->retained = 0;
      Server::$pendingBytes = self::$total;
   }

   /**
    * Start a freshly-forked worker ledger.
    *
    * The server calls this before Worker::Boot, while no worker-owned
    * connection can exist. A token that survived the fork (a static owner
    * the master filled) belongs to the old generation: it holds nothing in
    * the new ledger, so its next `reserve()` charges in full and its next
    * `release()` returns nothing. Runtime callers must never reset live owners.
    */
   public static function reset (): void
   {
      self::$total = 0;
      self::$held = [0, 0, 0];
      self::$epoch++;
      Server::$pendingBytes = 0;
   }

   public function __destruct ()
   {
      $this->release();
   }
}
