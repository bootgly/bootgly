<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu\Items;


use Bootgly\CLI\Terminal\components\Menu\Items\Items;
use Bootgly\CLI\Terminal\components\Menu\Menu;


final class Separators extends Items
{
   // * Config
   // ...

   // * Data
   // ...

   // * Meta
   // ...


   public function add (string $separator) : Separator
   {
      $Separator = new Separator($this->Menu);
      $Separator->separator = $separator;

      Items::push($Separator);

      return $Separator;
   }

   public function compile (Separator $Separator)
   {
      $Menu = $this->Menu;

      // * Config
      // @ Displaying
      $Orientation = $this->Orientation->get();

      // * Data
      // ...

      // * Meta
      // ...

      $compiled = '';

      $separator = $Separator->separator;

      // * Config
      // @ Displaying
      switch ($Orientation) {
         case $Orientation::Vertical:
            $characters = strlen($separator);

            if ($characters > 0) {
               $separators = str_repeat($separator, $Menu->width / $characters);
               $compiled .= "{$separators}\n";
            }

            break;
         case $Orientation::Horizontal:
            $compiled = "{$separator}";
      }

      return $compiled;
   }
}
