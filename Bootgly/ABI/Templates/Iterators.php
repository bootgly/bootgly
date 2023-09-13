<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates;


class Iterators
{
   // * Data
   private static array $Iterators = [];
   // * Meta
   public static int $depth = 0;


   public static function queue (array|object &$iteratee) : Iterator
   {
      self::$depth++;

      $Iterator = new Iterator(
         $iteratee,
         self::$Iterators[count(self::$Iterators) - 1] ?? null,
         self::$depth
      );

      self::$Iterators[] = &$Iterator;

      return $Iterator;
   }
   public static function dequeue () : ? Iterator
   {
      self::$depth--;

      array_pop(self::$Iterators);

      $iterators = count(self::$Iterators);
      if ($iterators > 0) {
         return self::$Iterators[$iterators - 1];
      }

      return null;
   }
}
