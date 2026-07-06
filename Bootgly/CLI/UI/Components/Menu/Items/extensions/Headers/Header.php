<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
