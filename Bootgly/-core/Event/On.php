<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Event;


#[\AllowDynamicProperties]
class On
{
   public function __get ($name) : \Closure
   {
      return ($this->$name);
   }

   public function __set ($name, \Closure $value)
   {
      $this->$name = $value;
   }
}
