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


use Bootgly\CLI\UI\Components\Menu\Items;


final class Headers extends Items
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   public function add (string $header): Header
   {
      $Header = new Header;
      // * Data
      $Header->header = $header;

      Items::push($Header);

      return $Header;
   }

   public function compile (Header $Header): string
   {
      #$Menu = $this->Menu;

      // @ Headers
      // * Config
      // ...
      // * Data
      // ...
      // * Metadata
      // ...

      // @ Header
      // * Config
      // ...
      // * Data
      // ...
      // * Metadata
      // ...

      // @
      $compiled = '';

      $header = $Header->header;

      $compiled = "{$header}";

      return $compiled;
   }
}
