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
   public array $rows;
   // * Meta


   public function __construct ($Table)
   {
      $this->Table = $Table;

      $this->Row = $Table->Row;

      // * Config
      // * Data
      $this->rows = [];
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

   public function set (array $rows)
   {
      $this->rows = $rows;
   }
   public function append (array $row)
   {
      $this->rows[] = $row;
   }

   public function render (? array $rows = null)
   {
      $rows ??= $this->rows;

      if (count($rows) === 0) {
         return false;
      }

      foreach ($rows as $index => $row) {
         // TODO use $index to set configurations per row
         $this->Row->render($row);
      }
   }
}