<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\components;


use Bootgly\Data\Table as DataTable;

use Bootgly\CLI\Terminal\components\Table\Cells;
use Bootgly\CLI\Terminal\components\Table\Columns;
use Bootgly\CLI\Terminal\components\Table\Row;
use Bootgly\CLI\Terminal\components\Table\Rows;
use Bootgly\CLI\Terminal\Output;


class Table
{
   public DataTable $Data;

   private Output $Output;

   // * Config
   // ...

   // * Data
   // @ Style
   protected array $borders;

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
      $line = match ($position) {
         'top' => $this->borders['top-left'],
         'mid' => $this->borders['mid-left'],
         'bottom' => $this->borders['bottom-left']
      };

      foreach ($this->Columns->widths as $index => $width) {
         if ($index > 0) {
            $line .= match($position) {
               'top' => $this->borders['top-mid'],
               'mid' => $this->borders['mid-mid'],
               'bottom' => $this->borders['bottom-mid']
            };
         }

         $border = match($position) {
            'top' => $this->borders['top'],
            'mid' => $this->borders['mid'],
            'bottom' => $this->borders['bottom']
         };
         $line .= str_repeat($border, $width + 2);
      }

      $line .= match ($position) {
         'top' => $this->borders['top-right'],
         'mid' => $this->borders['mid-right'],
         'bottom' => $this->borders['bottom-right']
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
