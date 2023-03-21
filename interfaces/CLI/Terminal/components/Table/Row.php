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
   // ...

   // * Data
   // ...

   // * Meta
   // ...


   public function __construct ($Table)
   {
      $this->Table = $Table;

      // * Config
      // ...

      // * Data
      // ...

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

   public function render (array $row)
   {
      if ( count($row) === 1 && @$row[0] === '@---;' ) {
         $this->Table->border(position: 'mid');
         return;
      }

      // ! Table
      $borders = &$this->Table->borders;
      // ! Cells
      $aligment = $this->Cells->alignment;
      // ! Columns
      $widths = &$this->Columns->widths;
      $columns = $this->Columns->count;

      $output = $borders['left'] . ' ';

      $column = 0;
      while ($column < $columns) {
         if ($column > 0) {
            $output .= ' ' . $borders['middle'];
         }

         $output .= __String::pad(
            input: @$row[$column],
            length: $widths[$column],
            padding: ' ',
            type: $aligment
         );

         if ($column > 0) {
            $output .= ' ';
         }

         $column++;
      }

      $output .= $borders['right'];
      $output .= "\n";

      // TODO use Output as trait?
      $this->Output->write($output);
   }
}
