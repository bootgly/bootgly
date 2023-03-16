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


class Columns
{
   private Table $Table;

   // * Config

   // * Data

   // * Meta
   public array $widths;
   public int $count;


   public function __construct ($Table)
   {
      $this->Table = $Table;

      // * Config

      // * Data

      // * Meta
      $this->widths = [];
      $this->count = 0;
   }
   public function __get ($name)
   {
      return $this->Table->$name;
   }
   public function __call ($name, $arguments)
   {
      return $this->Table->$name(...$arguments);
   }

   public function calculate () : array
   {
      $widths = [];

      // ! Headers
      foreach ($this->headers as $index => $header) {
         $widths[$index] = mb_strlen($header);
      }

      // ! Footers
      foreach ($this->footers as $index => $footer) {
         $widths[$index] = max($widths[$index] ?? 0, mb_strlen($footer));
      }

      // ! Rows
      foreach ($this->Rows->rows as $row) {
         foreach ($row as $index => $value) {
            $widths[$index] = max($widths[$index] ?? 0, mb_strlen($value));
         }
      }

      $this->count = count($widths);

      return $this->widths = $widths;
   }
}