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


use function count;

use Bootgly\ABI\Code\__String;
use Bootgly\CLI\UI\Components\Table;


class Row
{
   private Table $Table;

   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (Table $Table)
   {
      $this->Table = $Table;

      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      // ...
   }

   /**
    * Render a row
    *
    * @param array<string> $row
    * @param string $section
    */
   public function render (array $row, string $section): void
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
      $widths = $Columns->Width->get();

      $output = $borders['left'];
      if ($borders['left']) {
         $output .= ' ';
      }

      // ! One cell is one space, the content padded to its column and one
      //   space — the `width + 2` every border line draws. A trailing space
      //   per cell on top of the separator's leading one widened every
      //   column after the first by one, so tables with three or more
      //   columns never lined up with their borders.
      foreach ($widths as $column_index => $width) {
         if ($column_index > 0) {
            $output .= ' ' . $borders['middle'];
         }

         $output .= __String::pad( // @phpstan-ignore-line
            string: $row[$column_index] ?? '',
            length: $widths[$column_index], // @phpstan-ignore-line
            padding: ' ',
            type: $aligment
         );
      }

      if ($borders['right']) {
         $output .= ' ';
      }
      $output .= $borders['right'];
      $output .= "\n";

      $this->Table->Output->write($output);
   }
}
