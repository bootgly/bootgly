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


class Iterator
{
   // * Data
   private array|object $iteratee;
   public ? Iterator $Parent;
   public int $depth;
   // * Metadata
   public int $index;
   protected int $count;

   protected int $iteration;
   protected int $remaining;


   public function __construct (array|object &$iteratee, ? Iterator $Parent = null, int $depth)
   {
      // * Data
      $this->iteratee = &$iteratee;
      $this->Parent = $Parent;
      $this->depth = $depth;
      // * Metadata
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
         case 'key': return key($this->iteratee);
         case 'value': return current($this->iteratee);

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

   public function next () : void
   {
      next($this->iteratee);
      $this->index++;
   }
}
