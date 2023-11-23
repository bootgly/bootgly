<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Table;


use Bootgly\CLI\UI\Table\Table;


class Cells
{
   private Table $Table;

   // * Config
   public int $alignment;

   // * Data
   // ...

   // * Meta
   // ...


   public function __construct ($Table)
   {
      $this->Table = $Table;

      // * Config
      $this->alignment = 1;

      // * Data
      // ...

      // * Meta
      // ...
   }

   public function align (string $aligment) : int
   {
      return $this->alignment = match ($aligment) {
         1, 'left' => 1,
         0, 'right' => 0,
         2, 'center' => 2,

         default => 1
      };
   }
}
