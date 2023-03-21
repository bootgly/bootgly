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


class Rows
{
   private Table $Table;

   private Row $Row;

   // * Config
   // ...

   // * Data
   public ? array $rows;

   // * Meta
   // ...


   public function __construct ($Table)
   {
      $this->Table = $Table;

      $this->Row = $Table->Row;

      // * Config
      // ...

      // * Data
      $this->rows = &$Table->Data->rows;

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

   public function render ()
   {
      $data = $this->rows;

      if (count($data) === 0) {
         return false;
      }

      foreach ($data as $section => $rows) {
         // @ Pre
         match ($section) {
            'header' => $this->Table->border(position: 'top'),
            'body' => $this->Table->border(position: 'top'),
            'footer' => $this->Table->border(position: 'bottom'),
            default => null
         };

         foreach ($rows as $metadata => $rows) {
            // TODO use $metadata to set configurations per row
            $this->Row->render($rows);
         }

         // @ Post
         match ($section) {
            #'header' => $this->Table->border(position: 'top'),
            #'body' => $this->Table->border(position: 'bottom'),
            'footer' => $this->Table->border(position: 'bottom'),
            default => null
         };
      }
   }
}
