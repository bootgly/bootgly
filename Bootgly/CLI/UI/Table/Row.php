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


use Bootgly\ABI\Data\__String;
use const Bootgly\CLI;
use Bootgly\CLI\UI\Table\Table;


class Row
{
   private Table $Table;

   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct ($Table)
   {
      $this->Table = $Table;

      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      // ...
   }

   public function render (array $row, string $section)
   {
      if ( count($row) === 1 && @$row[0] === '@---;' ) {
         $this->Table->border(position: 'mid', section: $section);
         return;
      }

      // ! Table
      $borders = $this->Table->borders;
      // > Cells
      $aligment = $this->Table->Cells->alignment;
      // > Columns
      $Columns = $this->Table->Columns;
      $Columns->section = $section;
      $widths = $Columns->widths;

      $output = $borders['left'];
      if ($borders['left']) {
         $output .= ' ';
      }

      foreach ($widths as $column_index => $width) {
         if ($column_index > 0) {
            $output .= ' ' . $borders['middle'];
         }

         $output .= __String::pad( // @phpstan-ignore-line
            string: $row[$column_index] ?? '',
            length: $widths[$column_index],
            padding: ' ',
            type: $aligment
         );

         if ($column_index > 0) {
            $output .= ' ';
         }
      }

      $output .= $borders['right'];
      $output .= "\n";

      // TODO use Output as trait?
      $Terminal = CLI->Terminal;
      $Terminal->Output->write($output);
   }
}
