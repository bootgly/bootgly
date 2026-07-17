<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;


use function max;
use function min;


/**
 * Inbound HTTP/2 request-body accountant.
 *
 * One instance belongs to one Decoder_HTTP2 connection. `$retained` bounds
 * the sum of that connection's unfinished stream bodies; static `$total`
 * bounds the same resource across every decoder in the current worker.
 * Workers are single-process event loops, so both checks and increments are
 * one synchronous operation with no cross-thread race.
 */
final class Bodies
{
   // * Config
   /** Per-connection ceiling. */
   public readonly int $limit;
   /** Per-worker ceiling. */
   public readonly int $worker;
   // * Data
   /** Bytes retained by every HTTP/2 connection in this worker. */
   private static int $total = 0;
   /** Bytes retained by this connection. */
   public protected(set) int $retained;


   public function __construct (int $limit, int $worker)
   {
      $this->retained = 0;
      $this->limit = max(0, $limit);
      $this->worker = max(0, $worker);
   }

   /**
    * Atomically reserve decoded body bytes against both ceilings.
    * The caller must release every successful reservation.
    */
   public function reserve (int $bytes): bool
   {
      if ($bytes <= 0) {
         return true;
      }
      if (
         $bytes > $this->limit - $this->retained
         || $bytes > $this->worker - self::$total
      ) {
         return false;
      }

      $this->retained += $bytes;
      self::$total += $bytes;

      return true;
   }

   /** Release a previous reservation, saturating against duplicate cleanup. */
   public function release (int $bytes): void
   {
      if ($bytes <= 0 || $this->retained === 0) {
         return;
      }

      $released = min($bytes, $this->retained);
      $this->retained -= $released;
      self::$total = max(0, self::$total - $released);
   }

   public function __destruct ()
   {
      $this->release($this->retained);
   }
}
