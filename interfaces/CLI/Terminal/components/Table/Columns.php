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


class Columns
{
   private Table $Table;

   // * Config
   // ...

   // * Data
   // ...

   // * Meta
   public array $widths;
   public int $count;


   public function __construct ($Table)
   {
      $this->Table = $Table;

      // * Config
      // ...

      // * Data
      // ...

      // * Meta
      $this->widths = [];
      $this->count = 0;
   }
   public function __get ($name)
   {
      return $this->Table->$name;
   }
   public function __call ($name, $arguments)
   {
      return $this->Table->$name(...$arguments);
   }

   public function calculate () : bool
   {
      $widths = [];

      foreach ($this->Table->Data->rows as $section => $rows) {
         // @ Pre
         // ...

         foreach ($rows as $row) {
            foreach ($row as $column => $data) {
               $widths[$column] = max($widths[$column] ?? 0, mb_strlen($data));
            }
         }

         // @ Post
         // ...
      }

      $this->count = count($widths);
      $this->widths = $widths;

      return true;
   }
}
