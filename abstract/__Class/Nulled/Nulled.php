<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\__Class;


// TODO refactor
class Nulled
{
   public function __construct ()
   {
      // TODO
   }

   public function __get ($name)
   {
      return $this;
   }
   public function __set ($name, $value)
   {
      $this->$name = $value;
   }

   public function __call ($name, $arguments)
   {
      return null;
   }
}
