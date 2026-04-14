<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\Syntax\Imports\Analyzer;


use const T_WHITESPACE;
use function is_array;
use function strlen;


class Tokens
{
   // * Config
   // * Data
   /** @var array<int,mixed> */
   public array $items;
   // * Metadata
   public readonly int $count;


   /**
    * @param array<int,mixed> $items
    * @param int $count
    */
   public function __construct (array &$items, int $count)
   {
      $this->items = &$items;
      $this->count = $count;
   }


   /**
    * Rewind from position to find previous meaningful (non-whitespace) token.
    *
    * @param int $i Current position
    *
    * @return mixed Token or null
    */
   public function rewind (int $i): mixed
   {
      for ($j = $i - 1; $j >= 0; $j--) {
         $token = $this->items[$j];

         if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
         }

         // @ Skip error suppression operator
         if ($token === '@') {
            continue;
         }

         return $token;
      }

      return null;
   }

   /**
    * Advance from position to find next meaningful (non-whitespace) token.
    *
    * @param int $i Current position
    *
    * @return mixed Token or null
    */
   public function advance (int $i): mixed
   {
      for ($j = $i + 1; $j < $this->count; $j++) {
         $token = $this->items[$j];

         if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
         }

         return $token;
      }

      return null;
   }

   /**
    * Locate byte offset of a token at position.
    *
    * @param int $index Token index
    * @param bool $end If true, locate end offset (after token)
    *
    * @return int
    */
   public function locate (int $index, bool $end = false): int
   {
      $offset = 0;
      for ($i = 0; $i < $index; $i++) {
         $token = $this->items[$i];
         if (is_array($token)) {
            /** @var string $content */
            $content = $token[1];
            $offset += strlen($content);
         }
         else {
            /** @var string $token */
            $offset += strlen($token);
         }
      }

      if ($end) {
         $token = $this->items[$index];
         if (is_array($token)) {
            /** @var string $content */
            $content = $token[1];
            $offset += strlen($content);
         }
         else {
            /** @var string $token */
            $offset += strlen($token);
         }
      }

      return $offset;
   }
}
