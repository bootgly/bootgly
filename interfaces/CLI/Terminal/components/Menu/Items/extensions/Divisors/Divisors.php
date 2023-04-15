<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Menu\Items\extensions\Divisors;


use Bootgly\CLI\Terminal\components\Menu\Items;
use Bootgly\CLI\Terminal\components\Menu\Menu;


final class Divisors extends Items
{
   // * Config
   // ...

   // * Data
   // ...

   // * Meta
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
      // * Meta
      // ...

      // @ Divisor
      // * Config
      // ...
      // * Data
      $characters = $Divisor->characters;
      // * Meta
      $length = strlen($characters);

      // @
      $compiled = '';

      if ($length === 0) {
         return $compiled;
      }

      switch ($Orientation) {
         case $Orientation::Vertical:
            $divisor = str_repeat($characters, Menu::$width / $length);

            $compiled .= "{$divisor}\n";

            break;
         case $Orientation::Horizontal:
            $compiled = "{$characters}";
      }

      return $compiled;
   }
}
