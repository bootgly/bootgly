<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates;


use function array_pop;
use function count;
use Countable;


class Iterators
{
   // * Data
   /** @var Iterator[] */
   private static array $Iterators = [];
   // * Metadata
   public static int $depth = 0;


   /**
    * @param array<mixed>|Countable $iteratee
    *
    * @return Iterator
    */
   public static function queue (array|Countable &$iteratee): Iterator
   {
      self::$depth++;

      $Iterator = new Iterator(
         $iteratee,
         self::$depth,
         self::$Iterators[count(self::$Iterators) - 1] ?? null,
      );

      self::$Iterators[] = &$Iterator;

      return $Iterator;
   }
   public static function dequeue (): null|Iterator
   {
      self::$depth--;

      array_pop(self::$Iterators);

      $iterators = count(self::$Iterators);
      // ?: The enclosing loop takes the metavar back
      if ($iterators > 0) {
         return self::$Iterators[$iterators - 1];
      }

      // : No loop is running anymore — null is what makes the `$_?->next();`
      //   a later `@continue` emits short-circuit instead of fataling
      return null;
   }
   /**
    * Discard the loop stack — used when a render fails mid-loop.
    */
   public static function reset (): void
   {
      self::$Iterators = [];
      self::$depth = 0;
   }
}
