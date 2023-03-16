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


use Bootgly\__String;
use Bootgly\CLI\Terminal\components\Table;


class Row
{
   private Table $Table;

   // * Config
   // * Data
   // * Meta


   public function __construct ($Table)
   {
      $this->Table = $Table;

      // * Config
      // * Data
      // * Meta
   }
   public function __get ($name)
   {
      return $this->Table->$name;
   }
   public function __call ($name, $arguments)
   {
      return $this->Table->$name(...$arguments);
   }

   public function render (array $row)
   {
      if ( count($row) === 1 && @$row[0] === '@---;' ) {
         $this->printHorizontalLine('mid');
         return;
      }

      $output = $this->borders['left'] . ' ';

      $column = 0;
      while ($column < $this->Columns->count) {
         if ($column > 0) {
            $output .= ' ' . $this->borders['middle'];
         }

         $output .= __String::pad(
            input: @$row[$column],
            length: $this->Columns->widths[$column],
            padding: ' ',
            type: $this->Cells->alignment
         );

         if ($column > 0) {
            $output .= ' ';
         }

         $column++;
      }

      $output .= $this->borders['right'];
      $output .= "\n";

      $this->Output->write($output);
   }
}