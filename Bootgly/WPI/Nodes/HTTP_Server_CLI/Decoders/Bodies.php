<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use function max;

use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;


/**
 * Worker-wide accountant for unfinished HTTP request bodies held in memory.
 *
 * One instance belongs to one HTTP/1 body decoder (`Decoder_Waiting`,
 * `Decoder_Chunked` or `Decoder_Downloading`) or to the aggregate body token
 * of one HTTP/2 connection or captured Request snapshot. `Request::$maxBodySize`
 * already bounds a SINGLE body; this bounds their cross-protocol SUM, which is
 * what peers opening N connections and finishing none of them actually spend.
 *
 * `reserve()` is absolute, not incremental: the caller states the bytes it is
 * about to hold and the ledger moves by the difference. A decoder therefore
 * cannot double-count a retry or under-release a partial drain — the two bug
 * classes an incremental ledger invites.
 *
 * Every reservation also states its allocator footprint (`Buffers::weigh()` of
 * each held string, summed), which the worker memory budget charges in its
 * Inbound share (`Shares::Inbound`, at most half of
 * `TCP_Server_CLI::$maxWorkerPendingBytes`). `$maxWorkerBodySize` stays a
 * subordinate ceiling on the raw bytes.
 *
 * Workers are single-process event loops, so the check and the increment are
 * one synchronous operation with no cross-thread race.
 */
final class Bodies
{
   // * Config
   /**
    * Per-worker ceiling, in bytes, on the sum of every unfinished in-memory
    * HTTP/1 and HTTP/2 request body. HTTP/2's per-connection ceiling remains a
    * subordinate control. Multipart FILE parts are not counted here — they
    * stream to disk under `Decoder_Downloading\Downloads`, which has its own
    * aggregate — but multipart text parts are, because they are held in memory
    * exactly like a Content-Length body.
    */
   public static int $maxWorkerBodySize = 64 * 1024 * 1024; // @ 64 megabytes

   // * Data
   /** Bytes retained by every HTTP body decoder in this worker. */
   private static int $total = 0;
   /** Bytes retained by this decoder. */
   public protected(set) int $retained = 0;
   /**
    * Footprint of the parsed request head this decoder keeps with the body
    * (`Request\Frame::weigh()`), charged with it — see `hold()`.
    */
   public private(set) int $head = 0;

   // * Metadata
   /** This decoder's footprint in the worker memory budget (Inbound share). */
   private Buffers $Buffers;
   /** Footprint of the body bytes this decoder holds, the head excluded. */
   private int $footprint = 0;


   public function __construct ()
   {
      // * Metadata
      $this->Buffers = new Buffers(Shares::Inbound);
   }

   /**
    * Hold exactly `$bytes` for this decoder, spending `$footprint` bytes of
    * the worker memory budget, and move both ledgers by the difference.
    * Returns false when growth does not fit either one, leaving the previous
    * reservation untouched — the caller must reject and then `release()`.
    *
    * @param int $bytes The raw bytes held once this reservation succeeds.
    * @param int $footprint Their allocator footprint: `Buffers::weigh()` of
    *                       each held string, summed.
    */
   public function reserve (int $bytes, int $footprint): bool
   {
      // !
      $wanted = max(0, $bytes);
      $growth = $wanted - $this->retained;

      // ? Shrinking always fits
      if ($growth > 0 && $growth > self::$maxWorkerBodySize - self::$total) {
         return false;
      }
      // ? The footprint — with the kept head — must fit the Inbound share
      //   of the worker budget
      $footprint = max(0, $footprint);
      if ($this->Buffers->reserve($footprint + $this->head) === false) {
         return false;
      }

      // @
      $this->retained = $wanted;
      $this->footprint = $footprint;
      self::$total = max(0, self::$total + $growth);

      // :
      return true;
   }

   /**
    * Charge, with the body, the `$head` bytes of parsed request head the
    * decoder keeps until the body completes. Returns false when they do not
    * fit, leaving the previous reservation untouched — the caller must
    * reject the request.
    */
   public function hold (int $head): bool
   {
      // !
      $head = max(0, $head);

      // ?
      if ($this->Buffers->reserve($this->footprint + $head) === false) {
         return false;
      }

      // @
      $this->head = $head;

      // :
      return true;
   }

   /**
    * Return everything this decoder holds. Idempotent: a decoder released on
    * completion and again on disconnect must not credit the ledger twice.
    */
   public function release (): void
   {
      $this->Buffers->release();
      $this->footprint = 0;
      $this->head = 0;

      // ?
      if ($this->retained === 0) {
         return;
      }

      // @
      self::$total = max(0, self::$total - $this->retained);
      $this->retained = 0;
   }

   public function __destruct ()
   {
      $this->release();
   }
}
