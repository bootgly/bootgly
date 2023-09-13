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


use Iterator as Iterating;


class Iterator implements Iterating
{
   // * Data
   private array|object $iteratee;
   public ? Iterator $Parent;
   public int $depth;
   // * Meta
   public int $index;
   protected int $count;

   protected int $iteration;
   protected int $remaining;


   public function __construct (array|object &$iteratee, ? Iterator $Parent = null, int $depth)
   {
      // * Data
      $this->iteratee = $iteratee;
      $this->Parent = $Parent;
      $this->depth = $depth;
      // * Meta
      $this->index = 0;
      $this->count = count($iteratee);
      // ...dynamically:
      #key
      #value

      #iteration
      #remaining

      #isFirst
      #isLast
      #isOdd
      #isEven
   }
   public function __get ($name)
   {
      switch ($name) {
         case 'key': return $this->key();
         case 'value': return $this->current();

         case 'count':
            return $this->count;

         case 'iteration':
            return $this->index + 1;
         case 'remaining':
            return $this->count - ($this->index + 1);

         case 'isFirst':
            return $this->index === 0;
         case 'isLast':
            return $this->count === ($this->index + 1);
         case 'isOdd':
            return ($this->index + 1) % 2 === 0;
         case 'isEven':
            return ($this->index + 1) % 2 !== 0;

         default:
            return null;
      }
   }

   #[\ReturnTypeWillChange]
   public function rewind () : void
   {
      $this->index = 0;
      reset($this->iteratee);
   }

   #[\ReturnTypeWillChange]
   public function current () : mixed
   {
      return current($this->iteratee);
   }

   #[\ReturnTypeWillChange]
   public function key () : mixed
   {
      return key($this->iteratee);
   }

   #[\ReturnTypeWillChange]
   public function next () : void
   {
      ++$this->index;
      next($this->iteratee);
   }

   #[\ReturnTypeWillChange]
   public function valid () : bool
   {
      $key = $this->key();
      return isSet($this->iteratee[$key]);
   }
}
