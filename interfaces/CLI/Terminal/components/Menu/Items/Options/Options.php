<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu\Items\Options;


use ArrayAccess;
use Countable;
use Iterator;


class Options implements Countable, Iterator, ArrayAccess
{
   private array $options;
   private int $pointer;


   public function add (string $id, string $label)
   {
      $this->options[0][] = [
         'label' => $label
      ];

      return $this;
   }

   // @ Counting
   public function count () : int
   {
      return count($this->options[0]);
   }
   // @ Iterating
   public function current () : mixed
   {
      return $this->options[$this->pointer];
   }
   public function key () : mixed
   {
      return $this->options;
   }
   public function next () : void
   {
      $this->pointer++;
   }
   public function rewind () : void
   {
      $this->pointer = 0;
   }
   public function valid () : bool
   {
      return isSet($this->options[$this->pointer]);
   }
   // @ Acessing
   public function offsetExists ($key) : bool
   {
      return isSet($this->options[$key]);
   }
   public function offsetGet ($key) : mixed
   {
      return $this->options[$key];
   }
   public function offsetSet ($key, $value) : void
   {
      $this->options[$key] = $value;
   }
   public function offsetUnset ($key) : void
   {
      unSet($this->options[$key]);
   }
}
