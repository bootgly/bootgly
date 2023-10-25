<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Table;

use Bootgly\ABI\Data\__String;
use Bootgly\CLI\Terminal\components\Table\Table;


class Columns
{
   private Table $Table;

   // * Config
   // ...

   // * Data
   public int $count;
   // @ Width
   public array $widths;

   // * Meta
   // ...


   public function __construct ($Table)
   {
      $this->Table = $Table;

      // * Config
      // ...

      // * Data
      $this->count = 0;
      // @ Width
      $this->widths = [];

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

   public function calculate () : bool
   {
      $data = $this->Table->Data->get();
      foreach ($data as $section => $rows) {
         // @ Pre
         // ...

         foreach ($rows as $row_index => $row_data) {
            // TODO add per section auto width rows

            foreach ($row_data as $column_index => $column_data) {
               // @ Remove ANSI code characters from the string
               $column_data = preg_replace(__String::ANSI_ESCAPE_SEQUENCE_REGEX, '', $column_data);

               // @ Get column data length
               $column_data_length = mb_strlen($column_data);
               // @ Get current column width
               $column_index_width = $this->widths[$column_index];

               // @ Set column width
               $this->widths[$column_index] = max($column_data_length, $column_index_width);
            }
         }

         // @ Post
         // ...
      }

      $this->count = count($this->widths);
      $this->widths = array_values($this->widths);

      return true;
   }
}
