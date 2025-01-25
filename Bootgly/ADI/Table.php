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


use Throwable;
use Bootgly\ADI\Table\Section\Body;
use Bootgly\ADI\Table\Section\Footer;
use Bootgly\ADI\Table\Section\Header;
use Bootgly\ADI\Table\Section;


class Table
{
   // * Config
   // ...

   // * Data
   /** @var Header<array-key,mixed> */
   public Header $Header;
   /** @var Body<array-key,mixed> */
   public Body $Body;
   /** @var Footer<array-key,mixed> */
   public Footer $Footer;
   // ---
   /** @var array<int>|null */
   public null|array $columns; // set table data by columns...
   /** @var array<string,array<mixed>>|null */
   public null|array $rows {
      get {
         return [
            Section::Header->name => $this->Header->rows,
            Section::Body->name => $this->Body->rows,
            Section::Footer->name => $this->Footer->rows,
         ];
      }
   }
   // ---

   // * Metadata
   // ...


   public function __construct ()
   {
      // * Data
      $this->columns = null;
      // ---
      $this->Header = new Header;
      $this->Body = new Body;
      $this->Footer = new Footer;
   }

   // # Operations
   /**
    * Sums the values in a specified column of the body.
    *
    * @param int $column The column index to sum.
    *
    * @return false|int|float The sum of the values in the specified column or false on error.
    */
   public function sum (int $column): false|int|float
   {
      if ($column < 0) return 0;

      // @
      $sum = 0;
      try {
         foreach ($this->Body as $rowValue) {
            /** @var array<int,mixed> $rowValue */
            foreach ($rowValue as $columnIndex => $columnValue) {
               if (
                  is_numeric($columnValue) === false
                  && $sum > 0
               ) {
                  continue;
               }

               if ($columnIndex === $column) {
                  $sum += $columnValue; // @phpstan-ignore-line
               }
            }
         }
      }
      catch (Throwable) { // @phpstan-ignore-line
         return false;
      }

      return $sum;
   }
   /**
    * Subtracts the values in a specified column of the body.
    *
    * @param int $column The column index to subtract.
    *
    * @return false|int|float The result of subtracting the values in the specified column or false on error.
    */
   public function subtract (int $column): false|int|float
   {
      if ($column < 0) return 0;

      // @
      $result = 0;
      try {
         foreach ($this->Body as $rowValue) {
            /** @var array<int,mixed> $rowValue */
            foreach ($rowValue as $columnIndex => $columnValue) {
               if (is_numeric($columnValue) === false) {
                  continue;
               }

               if ($columnIndex === $column) {
                  $result -= $columnValue;
               }
            }
         }
      }
      catch (Throwable) { // @phpstan-ignore-line
         return false;
      }

      return $result;
   }
   /**
    * Multiplies the values in a specified column of the body.
    *
    * @param int $column The column index to multiply.
    *
    * @return int|float The result of multiplying the values in the specified column or false on error.
    */
   public function multiply (int $column): int|float
   {
      if ($column < 0) return 0;

      $result = 1;
      try {
         foreach ($this->Body as $rowValue) {
            /** @var array<int,mixed> $rowValue */
            foreach ($rowValue as $columnIndex => $columnValue) {
               if (is_numeric($columnValue) === false) {
                  continue;
               }

               if ($columnIndex === $column) {
                  $result *= $columnValue;
               }
            }
         }
      }
      catch (Throwable) { // @phpstan-ignore-line
         return 0;
      }

      return $result;
   }
   /**
    * Divides the values in a specified column of the body.
    *
    * @param int $column The column index to divide.
    *
    * @return false|null|float The result of dividing the values in the specified column or false on error.
    */
   public function divide (int $column): false|null|float
   {
      if ($column < 0) return 0;

      $result = null;
      try {
         foreach ($this->Body as $rowValue) {
            /** @var array<int,mixed> $rowValue */
            foreach ($rowValue as $columnIndex => $columnValue) {
               if (is_numeric($columnValue) === false) {
                  continue;
               }

               if ($columnIndex === $column) {
                  $result /= $columnValue;
               }
            }
         }
      }
      catch (Throwable) {
         return false;
      }

      return $result;
   }

   // # Search
   /**
    * Searches for a specific value in a specified column of the body.
    *
    * @param int $column The column index to search.
    * @param mixed $value The value to search for.
    *
    * @return bool True if the value is found, false otherwise.
    */
   public function find (int $column, mixed $value): bool
   {
      foreach ($this->Body as $rowValue) {
         /** @var array<int,mixed> $rowValue */
         foreach ($rowValue as $columnIndex => $columnValue) {
            if ($columnIndex === $column && $columnValue === $value) {
               return true;
            }
         }
      }

      return false;
   }
}
