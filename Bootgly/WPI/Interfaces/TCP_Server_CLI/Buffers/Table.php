<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;


use function chr;
use function is_int;
use function max;
use function ord;
use function str_repeat;
use function strlen;


/**
 * The table PHP builds for an array, followed key by key as it fills.
 *
 * Keys arrive in order, an integer key as an index and a string key as a
 * name. A first index below its starting slots (`0` to `7`, or `0` for a
 * singleton) starts a list: an index past the last
 * lands in its slots, and one past them doubles them while the list is over
 * half full; an index the list already holds replaces its value; any other
 * key turns the list into a map of its slots — twice them when the list is
 * full. A map doubles when full (an array of parsed values keeps no holes for
 * a map to reclaim instead), and counts a repeated key as a new one: it can
 * only overstate the slots. Sparse indexes can so leave an array with four
 * times the slots its values need.
 */
final class Table
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   /** Whether the table is a list — `null` before its first key. */
   public private(set) null|bool $list = null;
   /** Its slots: the ones it starts with (8, or 1 for a singleton), doubling. */
   public private(set) int $slots;
   /** Its slots up to its last value: a list keeps the holes it skipped. */
   private int $used = 0;
   /** Its values. */
   private int $count = 0;
   /** The list's filled slots, one bit each: a repeat from a hole. */
   private string $filled = "\0";
   /**
    * The bytes its slots take: a 16-byte value per list slot and 8 bytes of
    * hash, a 32-byte bucket and two 4-byte hash slots per map slot.
    */
   public int $bytes {
      get => $this->list === true ? $this->slots * 16 + 8 : $this->slots * 40;
   }


   /**
    * A table that starts with `$slots` slots: 8, PHP's minimum, or 1 for a
    * singleton (a nested array `parse_str()` builds since PHP 8.4.27 and
    * 8.5.12).
    */
   public function __construct (int $slots = 8)
   {
      // * Metadata
      $this->slots = max(1, $slots);
   }

   /**
    * Fill the table with one more `$key`.
    */
   public function fill (int|string $key): void
   {
      // ? The first key: an index below its starting slots starts a list
      $this->list ??= is_int($key) && $key >= 0 && $key < $this->slots;

      if ($this->list) {
         // @ An index past the last lands in the list's slots — doubled first
         //   when over half full and the index within twice them
         if (is_int($key) && $key >= $this->used) {
            if (($key >> 1) < $this->slots && $key >= $this->slots && ($this->slots >> 1) < $this->count) {
               $this->slots <<= 1;
               $this->filled .= str_repeat("\0", max(0, ($this->slots >> 3) - strlen($this->filled)));
            }
            if ($key < $this->slots) {
               $this->filled[$key >> 3] = chr(ord($this->filled[$key >> 3]) | 1 << ($key & 7));
               $this->used = $key + 1;
               $this->count++;

               return;
            }
         }
         // ? An index the list holds: its value is replaced
         if (
            is_int($key) && $key >= 0 && $key < $this->used
            && (ord($this->filled[$key >> 3]) & 1 << ($key & 7)) !== 0
         ) {
            return;
         }

         // ! Any other key turns the list into a map of its slots
         $this->list = false;
         $this->filled = '';
         // ? An index past the slots, or a negative one: twice them when the
         //   list is full
         if (is_int($key) && ($key < 0 || $key >= $this->used)) {
            if ($this->used >= $this->slots) {
               $this->slots <<= 1;
            }
            $this->count++;
            $this->used = $this->count;

            return;
         }
         // ? An index below the last fills a hole: no resize
         if (is_int($key)) {
            $this->count++;
            $this->used = $this->count;

            return;
         }
         // ! A name: the map doubles below when full
         $this->used = $this->count;
      }

      // @ The map: doubles when full
      if ($this->used >= $this->slots) {
         $this->slots <<= 1;
      }
      $this->used++;
      $this->count++;
   }
}
