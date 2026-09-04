<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * Inspired by Workerman\Timer
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events\Timer;


use function array_keys;
use Closure;
use Bootgly\ACI\Events\Timer;


/** Read-only timer ownership registry. */
final class Registry
{
   // * Metadata
   /** Timer-scoped status reader, bound once without exposing mutation APIs. */
   private static null|Closure $Reader = null;
   /** Timer-scoped identifier snapshot reader. */
   private static null|Closure $Snapshot = null;


   /**
    * Check whether a timer identifier is still live.
    *
    * @param int $id Timer identifier returned by Timer::add().
    *
    * @return bool
    */
   public static function check (int $id): bool
   {
      if (self::$Reader === null) {
         self::$Reader = Closure::bind(
            static function (int $id): bool {
               return ! empty(Timer::$status[$id]);
            },
            null,
            Timer::class,
         );
      }

      return (self::$Reader)($id);
   }

   /**
    * Snapshot every live timer identifier without exposing the task payloads.
    *
    * @return array<int>
    */
   public static function snapshot (): array
   {
      if (self::$Snapshot === null) {
         self::$Snapshot = Closure::bind(
            static function (): array {
               return array_keys(Timer::$status);
            },
            null,
            Timer::class,
         );
      }

      return (self::$Snapshot)();
   }
}
