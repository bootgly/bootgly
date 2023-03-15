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


use Bootgly\__String;
use Bootgly\CLI\Terminal\Output;


class Table
{
   private Output $Output;

   // * Config
   // * Data
   private $headers;
   private $rows;
   // * Meta


   public function __construct (Output $Output)
   {
      $this->Output = $Output;

      // * Config
      // * Data
      $this->headers = [];
      $this->rows = [];
      // * Meta
   }

   // ! Header(s)
   public function setHeaders (array $headers)
   {
      $this->headers = $headers;
   }

   // ! Row(s)
   public function addRow (array $row)
   {
      $this->rows[] = $row;
   }
   public function setRows (array $rows)
   {
      $this->rows = $rows;
   }

   private function printRow (array $row, array $columnWidths)
   {
      $rowOutput = '';

      foreach ($row as $key => $value) {
         $rowOutput .= '| ';
         $rowOutput .= __String::pad($value, $columnWidths[$key], ' ', STR_PAD_RIGHT);
         $rowOutput .= ' ';
      }
  
      $rowOutput .= "|\n";

      $this->Output->write($rowOutput);
   }

   // ! Column(s)
   private function calculateColumnWidths ()
   {
      $columnWidths = [];

      foreach ($this->headers as $header) {
         $columnWidths[] = mb_strlen($header);
      }

      foreach ($this->rows as $row) {
         foreach ($row as $key => $value) {
            $columnWidths[$key] = max($columnWidths[$key], mb_strlen($value));
         }
      }

      return $columnWidths;
   }

   // @ Line
   private function printHorizontalLine (array $columnWidths)
   {
      $lineOutput = '';

      foreach ($columnWidths as $width) {
         $lineOutput .= '+' . str_repeat('-', $width + 2);
      }

      $lineOutput .= "+\n";

      $this->Output->write($lineOutput);
   }

   public function render ()
   {
      // @ Calculate Column Widths
      $columnWidths = $this->calculateColumnWidths();

      // @ Print Headers
      $this->printHorizontalLine($columnWidths);
      $this->printRow($this->headers, $columnWidths);

      // @ Print Rows
      $this->printHorizontalLine($columnWidths);

      foreach ($this->rows as $row) {
         $this->printRow($row, $columnWidths);
      }

      $this->printHorizontalLine($columnWidths);
   }
}
