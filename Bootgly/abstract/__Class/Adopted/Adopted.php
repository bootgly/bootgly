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


class Adopted
{
   public ? object $Object = null;

   public function __construct (object $Object)
   {
      $this->Object = $Object;
   }
   public function __get ($name)
   {
      return @$this->Object->$name;
   }
}
