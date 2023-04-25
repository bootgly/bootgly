<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Iterator;


class __Iterable implements Iterator
{
   private array|object $iteratee;
   public int $index;


   public function __construct (array|object $iteratee)
   {
      $this->iteratee = $iteratee;
      $this->index = 0;
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
