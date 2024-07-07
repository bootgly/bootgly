<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events;


#[\AllowDynamicProperties]
class On
{
   public function __get (string $name): \Closure
   {
      return ($this->$name);
   }

   public function __set (string $name, \Closure $value): void
   {
      $this->$name = $value;
   }
}
