<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Menu\Items\extensions\Headers;


use Bootgly\CLI\UI\Menu\Items;


final class Headers extends Items
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   public function add (string $header) : Header
   {
      $Header = new Header;
      // * Data
      $Header->header = $header;

      Items::push($Header);

      return $Header;
   }

   public function compile (Header $Header)
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
