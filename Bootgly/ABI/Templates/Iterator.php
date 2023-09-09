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
   // * Meta
   public int $index;

   protected int $count;

   protected int $iteration;
   protected int $remaining;


   public function __construct (array|object $iteratee)
   {
      // * Data
      $this->iteratee = $iteratee;
      // * Meta
      $this->index = 0;
      // ...dynamically
      #count

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
         case 'count':
            return $this->count = count($this->iteratee);

         case 'iteration':
            return $this->index + 1;
         case 'remaining':
            $count = $this->count ??= count($this->iteratee);
            return $count - ($this->index + 1);

         case 'isFirst':
            return $this->index === 0;
         case 'isLast':
            $count = $this->count ??= count($this->iteratee);
            return $count === ($this->index + 1);
         case 'isOdd':
            return ($this->index + 1) % 2 === 0;
         case 'isEven':
            return ($this->index + 1) % 2 !== 0;

         default:
            return null;
      }
   }

   public function rewind () : void
   {
      $this->index = 0;
   }

   #[\ReturnTypeWillChange]
   public function current ()
   {
      return $this->iteratee[$this->index];
   }

   #[\ReturnTypeWillChange]
   public function key ()
   {
      return $this->index;
   }

   public function next () : void
   {
      ++$this->index;
   }

   public function valid () : bool
   {
      return isSet($this->iteratee[$this->index]);
   }
}
