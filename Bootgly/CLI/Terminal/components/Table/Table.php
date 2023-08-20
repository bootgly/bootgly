<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components\Table;


use Bootgly\ADI\Table as DataTable;

use Bootgly\CLI\Terminal\components\Table\ { Cells, Columns, Row, Rows };
use Bootgly\CLI\Terminal\Output;


class Table
{
   public DataTable $Data;

   private Output $Output;

   // * Config
   // ...

   // * Data
   // @ Style
   public array $borders;

   // * Meta
   // ...

   public Cells $Cells;
   public Columns $Columns;
   public Row $Row;
   public Rows $Rows;


   public function __construct (Output $Output)
   {
      $this->Data = new DataTable;

      $this->Output = $Output;

      // * Config
      // ...

      // * Data
      // @ Style
      $this->borders = [
         'top'          => '═',
         'top-left'     => '╔',
         'top-mid'      => '╤',
         'top-right'    => '╗',

         'bottom'       => '═',
         'bottom-left'  => '╚',
         'bottom-mid'   => '╧',
         'bottom-right' => '╝',

         'mid'          => '─',
         'mid-left'     => '╟',
         'mid-mid'      => '┼',
         'mid-right'    => '╢',
         'middle'       => '│ ',

         'left'         => '║',
         'right'        => '║',
      ];

      // * Meta
      // ...

      // @compose
      $this->Cells = new Cells($this);
      $this->Columns = new Columns($this);
      $this->Row = new Row($this);
      $this->Rows = new Rows($this);
   }
   public function __get ($name)
   {
      return $this->$name;
   }
   public function __set ($name, $value)
   {
      // TODO
   }
   public function __call ($name, $arguments)
   {
      return $this->$name(...$arguments);
   }

   // @ Border
   public function border (string $position)
   {
      // * Data
      $borders = $this->borders;

      // @
      $line = match ($position) {
         'top' => $borders['top-left'],
         'mid' => $borders['mid-left'],
         'bottom' => $borders['bottom-left']
      };

      foreach ($this->Columns->widths as $index => $width) {
         if ($index > 0) {
            $line .= match($position) {
               'top' => $borders['top-mid'],
               'mid' => $borders['mid-mid'],
               'bottom' => $borders['bottom-mid']
            };
         }

         $border = match ($position) {
            'top' => $borders['top'],
            'mid' => $borders['mid'],
            'bottom' => $borders['bottom']
         };

         $line .= str_repeat($border, $width + 2);
      }

      $line .= match ($position) {
         'top' => $borders['top-right'],
         'mid' => $borders['mid-right'],
         'bottom' => $borders['bottom-right']
      };

      $line .= "\n";

      $this->Output->write($line);
   }

   public function render ()
   {
      // ! Columns
      // Calculate columns width and count
      $this->Columns->calculate(); // TODO rename???

      // ! Rows
      $this->Rows->render();
   }
}
