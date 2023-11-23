<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Menu\Items\extensions\Divisors;


use Bootgly\CLI\UI\Menu\Item;


final class Divisor extends Item
{
   // * Config
   // ...

   // * Data
   public string $characters;

   // * Meta
   // ...


   public function __construct (string $characters = '')
   {
      parent::__construct();

      // * Data
      $this->characters = $characters;
   }
}
