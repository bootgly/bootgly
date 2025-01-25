<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Table;


use Bootgly\CLI\UI\Components\Table;
use Bootgly\ADI\Table\Section;


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
      /** @var array<string,array<int,array<int,mixed>>> */
      $data = $this->Table->Data->rows;

      if (count($data) === 0) {
         return;
      }

      foreach ($data as $section => $rows) {
         // @ Pre
         match ($section) {
            Section::Header->name =>
               $this->Table->border(position: 'top', section: $section),
            Section::Body->name =>
               $this->Table->border(position: 'top', section: $section),
            Section::Footer->name =>
               $this->Table->border(position: 'bottom', section: $section),
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
            Section::Footer->name =>
               $this->Table->border(position: 'bottom', section: $section),
            default => null
         };
      }
   }
}
