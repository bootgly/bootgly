<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;


use function max;


/**
 * Worker-wide accountant for unfinished HTTP/1 request bodies held in memory.
 *
 * One instance belongs to one body decoder: `Decoder_Waiting` (Content-Length),
 * `Decoder_Chunked` (chunked) and `Decoder_Downloading` (the multipart TEXT
 * parts it keeps in memory). `Request::$maxBodySize` already bounds a SINGLE
 * body; this bounds their SUM, which is what a peer that opens N connections
 * and finishes none of them actually spends.
 *
 * `reserve()` is absolute, not incremental: the caller states the footprint it
 * is about to hold and the ledger moves by the difference. A decoder therefore
 * cannot double-count a retry or under-release a partial drain — the two bug
 * classes an incremental ledger invites.
 *
 * Workers are single-process event loops, so the check and the increment are
 * one synchronous operation with no cross-thread race.
 */
final class Bodies
{
   // * Config
   /**
    * Per-worker ceiling, in bytes, on the sum of every unfinished in-memory
    * HTTP/1 request body. Mirrors `Decoder_HTTP2::$maxWorkerBodySize`, the
    * equivalent control on the HTTP/2 side. Multipart FILE parts are not
    * counted here — they stream to disk under `Decoder_Downloading\Downloads`,
    * which has its own aggregate — but multipart text parts are, because they
    * are held in memory exactly like a Content-Length body.
    */
   public static int $maxWorkerBodySize = 64 * 1024 * 1024; // @ 64 megabytes

   // * Data
   /** Bytes retained by every HTTP/1 body decoder in this worker. */
   private static int $total = 0;
   /** Bytes retained by this decoder. */
   public protected(set) int $retained = 0;


   /**
    * Hold exactly `$bytes` for this decoder, moving the worker ledger by the
    * difference. Returns false when growth does not fit, leaving the previous
    * reservation untouched — the caller must reject and then `release()`.
    */
   public function reserve (int $bytes): bool
   {
      // !
      $wanted = max(0, $bytes);
      $growth = $wanted - $this->retained;

      // ? Shrinking always fits
      if ($growth > 0 && $growth > self::$maxWorkerBodySize - self::$total) {
         return false;
      }

      // @
      $this->retained = $wanted;
      self::$total = max(0, self::$total + $growth);

      // :
      return true;
   }

   /**
    * Return everything this decoder holds. Idempotent: a decoder released on
    * completion and again on disconnect must not credit the ledger twice.
    */
   public function release (): void
   {
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
