<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;


use function array_pop;
use function gc_collect_cycles;
use Closure;
use Throwable;
use WeakReference;

use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;


/** Release one admission token when its Connection key is truly destroyed. */
final class Lease
{
   // * Data
   /** @var null|WeakReference<Connection> */
   private null|WeakReference $Reference;
   private null|Closure $Release;

   // * Metadata
   /** @var array<int,array{WeakReference<Connection>,Closure}> */
   private static array $Pending = [];
   /** Number of process-local release drains currently executing. */
   private static int $depth = 0;
   /** GC did not reach a quiescent pass inside the previous finite drain. */
   private static bool $pendingGC = false;
   /** Maximum cyclic-collection passes performed by one drain. */
   private const int GC_BUDGET = 8;


   /**
    * @param WeakReference<Connection> $Reference Managed Connection without strong ownership.
    * @param Closure $Release Tokenized ledger callback without peer ownership.
    */
   public function __construct (WeakReference $Reference, Closure $Release)
   {
      $this->Reference = $Reference;
      $this->Release = $Release;
   }

   /** Queue token validation after the owning WeakMap key reaches finalization. */
   public function __destruct ()
   {
      $Reference = $this->Reference;
      $Release = $this->Release;
      $this->Reference = null;
      $this->Release = null;
      if ($Reference === null || $Release === null) {
         return;
      }
      self::$Pending[] = [$Reference, $Release];
   }

   /** Release queued tokens only after resurrection is no longer possible. */
   public static function drain (): void
   {
      if (self::$depth > 0) {
         return;
      }
      self::$depth++;
      $stable = false;
      try {
         // ! flush() owns every detached local. PHP destroys those locals as
         //   the helper returns, while this outer lifecycle guard is still
         //   active, including captures released only at function teardown.
         $released = self::$pendingGC;
         try {
            $released = self::flush() || $released;
         }
         catch (Throwable) {
            // A helper-local destructor ran under the active guard. Complete
            // cyclic collection before reopening direct construction.
            $released = true;
         }
         if ($released) {
            for ($pass = 0; $pass < self::GC_BUDGET; $pass++) {
               try {
                  if (gc_collect_cycles() === 0) {
                     $stable = true;
                     break;
                  }
               }
               catch (Throwable) { // @phpstan-ignore catch.neverThrown
                  // A later pass retries after a hostile cyclic destructor.
               }
            }
         }
         else {
            $stable = true;
         }
      }
      finally {
         // ? If collection cannot quiesce within the finite budget, keep
         //   direct lifecycle construction fail-closed until a later drain.
         self::$pendingGC = $stable === false;
         self::$depth--;
      }
   }

   /** Drain one stable snapshot and preserve entries queued re-entrantly. */
   private static function flush (): bool
   {
      $Pending = self::$Pending;
      self::$Pending = [];
      $Retained = [];
      $released = false;
      while ($Pending !== []) {
         [$Reference, $Release] = array_pop($Pending);
         if ($Reference->get() !== null) {
            $Retained[] = [$Reference, $Release];
            unset($Reference, $Release);
            continue;
         }
         $released = true;
         try {
            $Release();
         }
         catch (Throwable) {
            // The dead key cannot be restored; later tuples are independent.
         }
         try {
            unset($Reference, $Release);
         }
         // @phpstan-ignore-next-line Closure captures may own throwing destructors.
         catch (Throwable) {
            // Ledger release has already been attempted.
         }
      }

      // @ A released callback can finalize another Lease and append it while
      //   this snapshot is draining. Carry that new entry into the next pass.
      self::$Pending = [...$Retained, ...self::$Pending];

      return $released;
   }

   /** Check whether a tokenized release callback is currently executing. */
   public static function guard (): bool
   {
      return self::$depth > 0 || self::$pendingGC;
   }
}
