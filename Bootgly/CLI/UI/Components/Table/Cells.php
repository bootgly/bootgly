<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
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

   public function align (string $alignment): int
   {
      return $this->alignment = match ($alignment) {
         'left' => 1,
         'right' => 0,
         'center' => 2,

         default => 1
      };
   }
}
