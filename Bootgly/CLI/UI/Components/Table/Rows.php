<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Table;


use function array_key_last;
use function count;

use Bootgly\CLI\UI\Components\Table;


class Rows
{
   private Table $Table;

   private Row $Row;

   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (Table $Table)
   {
      $this->Table = $Table;

      $this->Row = $Table->Row;

      // * Config
      // ...

      // * Data
      // ...

      // * Metadata
      // ...
   }

   public function render (): void
   {
      // ? Sections without rows never draw (no stray borders)
      $sections = [];
      foreach ($this->Table->Data->rows as $section => $rows) {
         if (count($rows) > 0) {
            $sections[$section] = $rows;
         }
      }

      // ?
      if ($sections === []) {
         return;
      }

      // @@ The first section opens the table; the following ones separate
      $opened = false;
      foreach ($sections as $section => $rows) {
         $this->Table->border(
            position: $opened ? 'mid' : 'top',
            section: $section
         );
         $opened = true;

         foreach ($rows as $metadata => $row) {
            // TODO use $metadata to set configurations per row
            $this->Row->render($row, $section);
         }
      }

      // @ The last section closes the table
      $this->Table->border(position: 'bottom', section: (string) array_key_last($sections));
   }
}
