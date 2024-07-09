<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Menu\Items\extensions\Headers;


use Bootgly\CLI\UI\Components\Menu\Item;


final class Header extends Item
{
   // * Config
   // ...

   // * Data
   public string $header;

   // * Metadata
   // ...


   public function __construct (string $characters = '')
   {
      parent::__construct();

      // * Data
      $this->header = $characters;
   }
}
