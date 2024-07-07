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


class Rows
{
   private Table $Table;

   private Row $Row;

   // * Config
   // ...

   // * Data
   /** @var array<array<array<string>>>> */
   public ? array $rows;

   // * Metadata
   // ...


   public function __construct (Table $Table)
   {
      $this->Table = $Table;

      $this->Row = $Table->Row;

      // * Config
      // ...

      // * Data
      $this->rows = &$Table->Data->rows;

      // * Metadata
      // ...
   }

   public function render (): void
   {
      $data = $this->rows;

      if (count($data) === 0) {
         return;
      }

      foreach ($data as $section => $rows) {
         // @ Pre
         match ($section) {
            'header' => $this->Table->border(position: 'top', section: $section),
            'body' => $this->Table->border(position: 'top', section: $section),
            'footer' => $this->Table->border(position: 'bottom', section: $section),
            default => null
         };

         foreach ($rows as $metadata => $row) {
            // TODO use $metadata to set configurations per row
            $this->Row->render($row, $section);
         }

         // @ Post
         match ($section) {
            #'header' => $this->Table->border(position: 'top', section: $section),
            #'body' => $this->Table->border(position: 'bottom', section: $section),
            'footer' => $this->Table->border(position: 'bottom', section: $section),
            default => null
         };
      }
   }
}
