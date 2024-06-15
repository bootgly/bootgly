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


use Bootgly\CLI\UI\Menu\Items;
use Bootgly\CLI\UI\Menu\Menu;
use Bootgly\CLI\UI\Menu\Orientation;

final class Divisors extends Items
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Characters to repeat (in Vertical Orientation) or to add (in Horizontal Orientation)
    */
   public function add (string $characters) : Divisor
   {
      $Divisor = new Divisor;
      // * Data
      $Divisor->characters = $characters;

      Items::push($Divisor);

      return $Divisor;
   }

   public function compile (Divisor $Divisor)
   {
      // @ Divisors
      // * Config
      // @ Displaying
      $Orientation = $this->Orientation->get();
      // * Data
      // ...
      // * Metadata
      // ...

      // @ Divisor
      // * Config
      // ...
      // * Data
      $characters = $Divisor->characters;
      // * Metadata
      $length = strlen($characters);

      // @
      $compiled = '';

      if ($length === 0) {
         return $compiled;
      }

      switch ($Orientation) {
         case Orientation::Vertical:
            $divisor = str_repeat($characters, Menu::$width / $length);

            $compiled .= "{$divisor}\n";

            break;
         case Orientation::Horizontal:
            $compiled = "{$characters}";
      }

      return $compiled;
   }
}
