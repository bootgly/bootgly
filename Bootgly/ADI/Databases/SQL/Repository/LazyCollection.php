<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


use function array_key_exists;
use function array_values;
use function count;
use function is_object;
use ArrayAccess;
use Countable;
use InvalidArgumentException;
use Iterator;


/**
 * Lazy-loaded collection for plural ORM relations.
 *
 * @implements ArrayAccess<int,mixed>
 * @implements Iterator<int,mixed>
 */
class LazyCollection implements ArrayAccess, Countable, Iterator
{
   // * Config
   public private(set) LazyBatch $Batch;
   public private(set) null|string $key;

   // * Data
   /** @var null|array<int,object> */
   private null|array $items = null;
   private int $position = 0;

   // * Metadata
   // ...


   public function __construct (LazyBatch $Batch, null|string $key)
   {
      // * Config
      $this->Batch = $Batch;
      $this->key = $key;
   }

   /**
    * Count loaded relation items.
    */
   public function count (): int
   {
      return count($this->load());
   }

   /**
    * Return current iterator item.
    */
   public function current (): mixed
   {
      return $this->load()[$this->position] ?? null;
   }

   /**
    * Fetch one loaded relation item by index.
    */
   public function fetch (int $index): null|object
   {
      return $this->load()[$index] ?? null;
   }

   /**
    * Return current iterator key.
    */
   public function key (): mixed
   {
      return $this->position;
   }

   /**
    * Load relation items for this parent.
    *
    * @return array<int,object>
    */
   public function load (): array
   {
      // ?: Cached parent items.
      if ($this->items !== null) {
         return $this->items;
      }

      // @ Parent group load.
      $this->items = array_values($this->Batch->fetch($this->key));

      // : Parent items.
      return $this->items;
   }

   /**
    * Advance iterator position.
    */
   public function next (): void
   {
      $this->position++;
   }

   /**
    * Check whether an offset exists.
    */
   public function offsetExists (mixed $offset): bool
   {
      return array_key_exists($offset, $this->load());
   }

   /**
    * Fetch one offset value.
    */
   public function offsetGet (mixed $offset): mixed
   {
      return $this->load()[$offset] ?? null;
   }

   /**
    * Set one offset value after loading local items.
    */
   public function offsetSet (mixed $offset, mixed $value): void
   {
      if (is_object($value) === false) {
         throw new InvalidArgumentException('ORM lazy collection accepts only objects.');
      }

      $items = $this->load();

      if ($offset === null) {
         $items[] = $value;
      }
      else {
         $items[$offset] = $value;
      }

      $this->set($items);
   }

   /**
    * Unset one offset value after loading local items.
    */
   public function offsetUnset (mixed $offset): void
   {
      $items = $this->load();
      unset($items[$offset]);
      $this->set($items);
   }

   /**
    * Reset local item cache.
    */
   public function reset (): void
   {
      $this->items = null;
      $this->position = 0;
   }

   /**
    * Rewind iterator position.
    */
   public function rewind (): void
   {
      $this->position = 0;
   }

   /**
    * Set already materialized local items.
    *
    * @param array<int,object> $items
    */
   public function set (array $items): void
   {
      $this->items = array_values($items);
      $this->position = 0;
   }

   /**
    * Check whether iterator position is valid.
    */
   public function valid (): bool
   {
      return array_key_exists($this->position, $this->load());
   }
}