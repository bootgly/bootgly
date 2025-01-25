<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Table;


use ArrayAccess;
use Iterator;

/**
 * @template TKey of array-key
 * @template TValue
 * 
 * @implements ArrayAccess<TKey, TValue>
 * @implements Iterator<TKey, TValue>
 */
class Rows implements ArrayAccess, Iterator
{
   /**
    * @var array<TKey,TValue>
    */
   public array $rows = [];


   /**
    * @param array<TKey,TValue> $rows
    */
   public function set (array $rows): void
   {
      $this->rows = $rows;
   }

   // --- ArrayAccess ---
   /**
    * @param TKey $offset
    */
   public function offsetExists ($offset): bool
   {
      return isSet($this->rows[$offset]);
   }

   /**
    * @param TKey $offset
    * @return TValue|null
    */
   public function offsetGet ($offset): mixed
   {
      return $this->rows[$offset] ?? null;
   }

   /**
    * @param TKey|null $offset
    * @param TValue $value
    */
   public function offsetSet ($offset, $value): void
   {
      if ($offset === null) {
         $this->rows[] = $value;
      }
      else {
         $this->rows[$offset] = $value;
      }
   }

   /**
    * @param TKey $offset
    */
   public function offsetUnset ($offset): void
   {
      unset($this->rows[$offset]);
   }

   // --- Iterator ---
   /**
    * @return TValue|false
    */
   public function current (): mixed
   {
      return current($this->rows);
   }

   /**
    * @return TKey|null
    */
   public function key (): mixed
   {
      return key($this->rows);
   }
   public function next (): void
   {
      next($this->rows);
   }
   public function rewind (): void
   {
      reset($this->rows);
   }
   public function valid (): bool
   {
      return key($this->rows) !== null;
   }
}
