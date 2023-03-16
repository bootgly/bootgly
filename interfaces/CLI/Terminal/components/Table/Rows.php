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


class Rows
{
   private Table $Table;

   private Row $Row;

   // * Config
   // * Data
   public ? array $rows;
   // * Meta


   public function __construct ($Table)
   {
      $this->Table = $Table;

      $this->Row = $Table->Row;

      // * Config
      // * Data
      $this->rows = null;
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

   public function set (array $rows, string $section = '')
   {
      if ($rows && (count($rows) !== count($rows, COUNT_RECURSIVE)) === false) {
         $rows = [$rows];
      }

      $this->rows[$section] = $rows;
   }
   public function append (array $row, string $section = '')
   {
      $this->rows[$section] = $row;
   }

   public function render (? array $data = null)
   {
      $data ??= $this->rows;

      if (count($data) === 0) {
         return false;
      }

      foreach ($data as $section => $rows) {
         // @ Pre
         match ($section) {
            'header' => $this->Table->border('top'),
            'body' => $this->Table->border('top'),
            'footer' => $this->Table->border('bottom'),
            default => null
         };

         foreach ($rows as $metadata => $rows) {
            // TODO use $metadata to set configurations per row
            $this->Row->render($rows);
         }

         // @ Post
         match ($section) {
            #'header' => $this->Table->border('top'),
            #'body' => $this->Table->border('bottom'),
            'footer' => $this->Table->border('bottom'),
            default => null
         };
      }
   }
}