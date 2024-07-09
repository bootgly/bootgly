<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Table;


class Cells
{
   // * Config
   public int $alignment;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct ()
   {
      // * Config
      $this->alignment = 1;

      // * Data
      // ...

      // * Metadata
      // ...
   }

   public function align (string $aligment): int
   {
      return $this->alignment = match ($aligment) {
         'left' => 1,
         'right' => 0,
         'center' => 2,

         default => 1
      };
   }
}
