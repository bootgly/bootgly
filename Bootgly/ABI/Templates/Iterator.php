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
   /** @var array<mixed>|object */
   private array|object $iteratee;
   public ? Iterator $Parent;
   public int $depth;
   // * Metadata
   public int $index;
   protected int $count;

   protected int $iteration;
   protected int $remaining;


   /**
    * @param array<mixed>|object $iteratee
    * @param int $depth
    * @param Iterator|null $Parent
    */
   public function __construct (array|object &$iteratee, int $depth, ? Iterator $Parent = null)
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
   public function __get (string $name): mixed
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

   public function next (): void
   {
      next($this->iteratee);
      $this->index++;
   }
}
