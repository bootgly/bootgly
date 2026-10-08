<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;


use function max;
use function min;

use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies as WorkerBodies;


/**
 * Inbound HTTP/2 request-body accountant.
 *
 * One instance belongs to one Decoder_HTTP2 connection. `$retained` bounds
 * the sum of that connection's unfinished stream bodies; `$Worker` owns
 * the connection's absolute token in the shared HTTP/1 + HTTP/2 worker ledger,
 * which also charges the bodies' allocator footprint — each stream body is
 * its own string, so the footprint is `Buffers::weigh()` of each, summed.
 * Workers are single-process event loops, so both checks and increments are
 * one synchronous operation with no cross-thread race.
 */
final class Bodies
{
   // * Config
   /** Per-connection ceiling. */
   public readonly int $limit;
   /** HTTP/2-only per-worker ceiling, subordinate to the shared aggregate. */
   public readonly int $worker;
   // * Data
   /** This connection's token in the cross-protocol worker ledger. */
   private WorkerBodies $Worker;
   /** Bytes retained by every HTTP/2 connection in this worker. */
   private static int $total = 0;
   /** Bytes retained by this connection. */
   public protected(set) int $retained;
   // * Metadata
   /** Allocator footprint of this connection's stream bodies. */
   private int $footprint = 0;


   public function __construct (int $limit, int $worker)
   {
      $this->Worker = new WorkerBodies;
      $this->retained = 0;
      $this->limit = max(0, $limit);
      $this->worker = max(0, $worker);
   }

   /**
    * Atomically reserve `$bytes` more decoded body bytes for a stream whose
    * body already holds `$held` bytes, against every ceiling. The footprint
    * grows by what that one body string grows by.
    * The caller must release every successful reservation.
    */
   public function reserve (int $bytes, int $held): bool
   {
      if ($bytes <= 0) {
         return true;
      }

      $held = max(0, $held);
      $growth = Buffers::weigh($held + $bytes) - Buffers::weigh($held);
      if (
         $bytes > $this->limit - $this->retained
         || $bytes > $this->worker - self::$total
         || $this->Worker->reserve(
            $this->retained + $bytes,
            $this->footprint + $growth
         ) === false
      ) {
         return false;
      }

      $this->retained += $bytes;
      $this->footprint += $growth;
      self::$total += $bytes;

      return true;
   }

   /**
    * Release one stream body of `$bytes` bytes, saturating against duplicate
    * cleanup.
    */
   public function release (int $bytes): void
   {
      if ($bytes <= 0 || $this->retained === 0) {
         return;
      }

      $released = min($bytes, $this->retained);
      $wanted = $this->retained - $released;
      $footprint = $wanted === 0
         ? 0
         : max(0, $this->footprint - Buffers::weigh($released));
      // Shrinking an absolute token always fits, even if configuration was
      // lowered below the currently live total after this reservation.
      $this->Worker->reserve($wanted, $footprint);
      $this->retained = $wanted;
      $this->footprint = $footprint;
      self::$total = max(0, self::$total - $released);
   }

   public function __destruct ()
   {
      $this->release($this->retained);
   }
}
