<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI;


class Table
{
   // * Config
   // ...

   // * Data
   /** @var array<int>|null */
   public ?array $columns; // set table data by columns...
   /** @var array<string,array<int,array<int,mixed>>>|null */
   public ?array $rows; // set table data by rows... // TODO: rename to sections?
   // ---
   #private ?array $header;
   #private ?array $body;
   #private ?array $footer;

   // * Metadata
   // ...


   public function __construct ()
   {
      // * Data
      $this->columns = null;
      $this->rows = null;
   }

   /**
    * Set the value of a specific section of the table.
    * 
    * @param string $name
    * @param array<int,array<int,mixed>> $value
    */
   public function __set (string $name, ? array $value): void
   {
      switch ($name) {
         case 'header':
         case 'body':
         case 'footer':
            if ($value && (count($value) !== count($value, COUNT_RECURSIVE)) === false) {
               $value = [$value];
            }

            $this->rows[$name] = $value;

            break;
      }
   }

   /**
    * Get the value of a specific section of the table.
    * 
    * @param string $section
    *
    * @return array<int,array<int,mixed>>
    */
   public function get (string $section = ''): array
   {
      return match ($section) {
         'header' => $this->rows['header'],
         'body'   => $this->rows['body'],
         'footer' => $this->rows['footer'],
         default  => $this->rows
      };
   }
   /**
    * Set the value of a specific section of the table.
    * 
    * @param null|array<int,array<int,mixed>> $header
    * @param null|array<int,array<int,mixed>> $body
    * @param null|array<int,array<int,mixed>> $footer
    */
   public function set (? array $header = null, ? array $body = null, ? array $footer = null): void
   {
      if ($header) {
         $this->__set("header", $header);
      }

      if ($body) {
         $this->__set("body", $body);
      }

      if ($footer) {
         $this->__set("footer", $footer);
      }
   }

   // # Operations
   /**
    * Sums the values in a specified column of the body.
    *
    * @param int $column The column index to sum.
    *
    * @return int The sum of the values in the specified column or false on error.
    */
   public function sum (int $column): false|int|float
   {
      if ($column < 0) return 0;

      $body = $this->rows['body'];

      $sum = 0;
      try {
         foreach ($body as $rowIndex => $rows) {
            foreach ($rows as $columnIndex => $data) {
               if ($column === $columnIndex) {
                  $sum += $data;
               }
            }
         }
      }
      catch (\Throwable) { // @phpstan-ignore-line
         return false;
      }

      return $sum;
   }
   /**
    * Subtracts the values in a specified column of the body.
    *
    * @param int $column The column index to subtract.
    *
    * @return int The result of subtracting the values in the specified column or false on error.
    */
   public function subtract (int $column): false|int|float
   {
      if ($column < 0) return 0;

      $data = $this->rows['body'];

      $result = 0;
      try {
         foreach ($data as $rowIndex => $rows) {
            foreach ($rows as $columnIndex => $data) {
               if ($column === $columnIndex) {
                  $result -= $data;
               }
            }
         }
      }
      catch (\Throwable) { // @phpstan-ignore-line
         return false;
      }

      return $result;
   }
   /**
    * Multiplies the values in a specified column of the body.
    *
    * @param int $column The column index to multiply.
    *
    * @return int The result of multiplying the values in the specified column or false on error.
    */
   public function multiply (int $column): int|float
   {
      if ($column < 0) return 0;

      $data = $this->rows['body'];

      $result = 1;
      try {
         foreach ($data as $rowIndex => $rows) {
            foreach ($rows as $columnIndex => $data) {
               if ($column === $columnIndex) {
                  $result *= $data;
               }
            }
         }
      }
      catch (\Throwable) { // @phpstan-ignore-line
         return 0;
      }

      return $result;
   }
   /**
    * Divides the values in a specified column of the body.
    *
    * @param int $column The column index to divide.
    *
    * @return float The result of dividing the values in the specified column or false on error.
    */
   public function divide (int $column): false|null|float
   {
      if ($column < 0) return 0;

      $data = $this->rows['body'];

      $result = null;
      try {
         foreach ($data as $rowIndex => $rows) {
            foreach ($rows as $columnIndex => $data) {
               if ($column === $columnIndex) {
                  if ($result === null) {
                     $result = $data;
                  } else {
                     $result /= $data;
                  }
               }
            }
         }
      }
      catch (\Throwable) {
         return false;
      }

      return $result;
   }

   // # Searchs
   /**
    * Searches for a specific value in a specified column of the body.
    *
    * @param int $column The column index to search.
    * @param mixed $value The value to search for.
    *
    * @return bool True if the value is found, false otherwise.
    */
   public function find (int $column, $value): bool
   {
      $data = $this->rows['body'];

      foreach ($data as $rowIndex => $rows) {
         foreach ($rows as $columnIndex => $data) {
            if ($column === $columnIndex && $value === $data) {
               return true;
            }
         }
      }

      return false;
   }
}
