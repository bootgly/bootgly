<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


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
