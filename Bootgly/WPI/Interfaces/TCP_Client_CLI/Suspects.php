<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI;


use function array_key_first;
use function count;
use function getmypid;
use function hrtime;
use function str_contains;


/**
 * The process's memory of addresses that recently failed a dial.
 *
 * A dial that resolves a name tries its addresses in order; an address whose
 * TCP stage failed (refused, unreachable, or silent through its share of the
 * budget) is a suspect for `WINDOW` and goes after the others on the next
 * dials — demoted, never skipped: a lone suspect is still tried. A dial that
 * reaches the address clears it.
 *
 * One memory per process (a forked process starts empty), keyed by address
 * and port, at most `CAPACITY` entries: past it the expired entries go first,
 * then the oldest.
 */
final class Suspects
{
   /** How long a failed address stays a suspect, in nanoseconds (10 s). */
   public const int WINDOW = 10_000_000_000;
   /** Entries kept per process, at most. */
   public const int CAPACITY = 256;

   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   /** @var array<string,int> When each suspect expires (`hrtime(true)` nanoseconds), by `address:port`. */
   private static array $expirations = [];
   /** The process the memory belongs to. */
   private static int $pid = 0;


   /**
    * Mark an address as a suspect for `WINDOW` from now.
    *
    * @param string $address A canonical IP address (an IPv6 one without brackets).
    * @param int $port The port the dial failed on.
    * @param null|int $now The monotonic time (`hrtime(true)` nanoseconds); `null` = now.
    */
   public static function mark (string $address, int $port, null|int $now = null): void
   {
      $now ??= (int) hrtime(true);
      self::check();

      $key = self::key($address, $port);
      // ! A renewed suspicion is the newest entry
      unset(self::$expirations[$key]);

      // ? Full: the expired entries go first, then the oldest
      if (count(self::$expirations) >= self::CAPACITY) {
         foreach (self::$expirations as $entry => $expiration) {
            if ($expiration <= $now) {
               unset(self::$expirations[$entry]);
            }
         }
         if (count(self::$expirations) >= self::CAPACITY) {
            unset(self::$expirations[array_key_first(self::$expirations)]);
         }
      }

      self::$expirations[$key] = $now + self::WINDOW;
   }

   /**
    * Clear an address: the dial reached it.
    *
    * @param string $address A canonical IP address (an IPv6 one without brackets).
    * @param int $port The port the dial reached.
    */
   public static function clear (string $address, int $port): void
   {
      self::check();

      unset(self::$expirations[self::key($address, $port)]);
   }

   /**
    * Split addresses into the ones to try first and the suspects, each in its order.
    *
    * @param list<string> $addresses Canonical IP addresses, in the order to try them.
    * @param int $port The port about to be dialed.
    * @param null|int $now The monotonic time (`hrtime(true)` nanoseconds); `null` = now.
    *
    * @return array{0:list<string>,1:list<string>} The fresh addresses, then the suspects.
    */
   public static function split (array $addresses, int $port, null|int $now = null): array
   {
      $now ??= (int) hrtime(true);
      self::check();

      $fresh = [];
      $suspects = [];
      foreach ($addresses as $address) {
         $expiration = self::$expirations[self::key($address, $port)] ?? 0;
         if ($expiration > $now) {
            $suspects[] = $address;
         }
         else {
            $fresh[] = $address;
         }
      }

      return [$fresh, $suspects];
   }

   /**
    * Start empty in a process that did not build the memory (a forked one).
    */
   private static function check (): void
   {
      $pid = (int) getmypid();
      if (self::$pid !== $pid) {
         self::$pid = $pid;
         self::$expirations = [];
      }
   }

   /**
    * The memory's key for an address and a port.
    */
   private static function key (string $address, int $port): string
   {
      return str_contains($address, ':') ? "[{$address}]:{$port}" : "{$address}:{$port}";
   }
}
