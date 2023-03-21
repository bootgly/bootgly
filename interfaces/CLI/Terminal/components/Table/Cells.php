<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Table;


use Bootgly\CLI\Terminal\components\Table;


class Cells
{
   private Table $Table;

   // * Config
   // ...

   // * Data
   public int $alignment;

   // * Meta
   // ...


   public function __construct ($Table)
   {
      $this->Table = $Table;

      // * Config
      // ...

      // * Data
      $this->alignment = 1;

      // * Meta
      // ...
   }
   public function __get ($name)
   {
      return $this->Table->$name;
   }
   public function __call ($name, $arguments)
   {
      return $this->Table->$name(...$arguments);
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
