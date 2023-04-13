<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu\Items\collections;


use Bootgly\CLI\Terminal\components\Menu\Items\Items;


final class Headers extends Items
{
   // * Config
   // ...

   // * Data
   // ...

   // * Meta
   // ...


   public function add (string $header) : Header
   {
      $Header = new Header;
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
      // * Meta
      // ...

      // @ Header
      // * Config
      // ...
      // * Data
      // ...
      // * Meta
      // ...

      // @
      $compiled = '';

      $header = $Header->header;

      $compiled = "{$header}";

      return $compiled;
   }
}
