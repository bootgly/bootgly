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


use Bootgly\ADI\Table as DataTable;

use Bootgly\API\Component;

use Bootgly\CLI\UI\Table\ { Cells, Columns, Row, Rows };
use Bootgly\CLI\Terminal\Output;


class Table extends Component
{
   public DataTable $Data;

   private Output $Output;

   // * Config
   // @ Style
   public const NO_BORDER_STYLE = [
      'top'          => '',
      'top-left'     => '',
      'top-mid'      => '',
      'top-right'    => '',

      'bottom'       => '',
      'bottom-left'  => '',
      'bottom-mid'   => '',
      'bottom-right' => '',

      'mid'          => '',
      'mid-left'     => '',
      'mid-mid'      => '',
      'mid-right'    => '',
      'middle'       => '',

      'left'         => '',
      'right'        => '',
   ];
   public const DEFAULT_STYLE = [
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
   public array $borders;

   // * Data
   // ...

   // * Metadata
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
      // @ Style
      $this->borders = self::DEFAULT_STYLE;

      // * Data
      // ...

      // * Metadata
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
   public function border (string $position, string $section)
   {
      // ! Columns
      $Columns = $this->Columns;
      $Columns->section = $section;

      // @
      // * Config
      $borders = $this->borders;
      // * Data
      // ...
      // * Metadata
      $line = match ($position) {
         'top' => $borders['top-left'],
         'mid' => $borders['mid-left'],
         'bottom' => $borders['bottom-left']
      };

      foreach ($Columns->widths as $column_index => $column_width) {
         if ($column_index > 0) {
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

         $line .= str_repeat($border, $column_width + 2);
      }

      $line .= match ($position) {
         'top' => $borders['top-right'],
         'mid' => $borders['mid-right'],
         'bottom' => $borders['bottom-right']
      };

      if ($line !== '') {
         $line .= "\n";
      }

      $this->Output->write($line);
   }

   public function render (int $mode = self::WRITE_OUTPUT)
   {
      // TODO on render RETURN OUTPUT

      // > Columns
      // @ Auto widen columns based on column data width
      $this->Columns->autowiden();

      // > Rows
      $this->Rows->render();
   }
}
