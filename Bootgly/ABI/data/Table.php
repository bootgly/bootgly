<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\data;


class Table
{
   // * Config
   // ...

   // * Data
   public ? array $columns;
   public ? array $rows;

   // * Meta
   // ...

   public function __construct ()
   {
      // * Data
      $this->columns = null;
      $this->rows = null;
   }

   public function __set (string $name, ? array $value)
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

   public function set (? array $header = null, ? array $body = null, ? array $footer = null)
   {
      if ($header) {
         $this->header = $header;
      }

      if ($body) {
         $this->body = $body;
      }

      if ($footer) {
         $this->footer = $footer;
      }
   }

   // @ Operations
   // Body
   /**
    * Sums the values in a specified column of the body.
    *
    * @param int $column The column index to sum.
    * @return int The sum of the values in the specified column.
    */
   public function sum (int $column) : int|float
   {
      $data = $this->rows['body'];

      $sum = 0;
      foreach ($data as $__row__ => $rows) {
         foreach ($rows as $__column__ => $data) {
            if ($column === $__column__) {
               $sum += $data;
            }
         }
      }

      return $sum;
   }
   /**
    * Subtracts the values in a specified column of the body.
    *
    * @param int $column The column index to subtract.
    * @return int The result of subtracting the values in the specified column.
    */
   public function subtract (int $column) : int|float
   {
      $data = $this->rows['body'];

      $result = 0;
      foreach ($data as $__row__ => $rows) {
         foreach ($rows as $__column__ => $data) {
               if ($column === $__column__) {
                  $result -= $data;
               }
         }
      }

      return $result;
   }
   /**
    * Multiplies the values in a specified column of the body.
    *
    * @param int $column The column index to multiply.
    * @return int The result of multiplying the values in the specified column.
    */
   public function multiply (int $column) : int|float
   {
      $data = $this->rows['body'];

      $result = 1;
      foreach ($data as $__row__ => $rows) {
         foreach ($rows as $__column__ => $data) {
               if ($column === $__column__) {
                  $result *= $data;
               }
         }
      }

      return $result;
   }
   /**
    * Divides the values in a specified column of the body.
    *
    * @param int $column The column index to divide.
    * @return float The result of dividing the values in the specified column.
    */
   public function divide (int $column) : null|float
   {
      $data = $this->rows['body'];

      $result = null;
      foreach ($data as $__row__ => $rows) {
         foreach ($rows as $__column__ => $data) {
               if ($column === $__column__) {
                  if ($result === null) {
                     $result = $data;
                  } else {
                     $result /= $data;
                  }
               }
         }
      }

      return $result;
   }
   /**
    * Searches for a specific value in a specified column of the body.
    *
    * @param int $column The column index to search.
    * @param mixed $value The value to search for.
    * @return bool True if the value is found, false otherwise.
    */
   public function find (int $column, $value) : bool
   {
      $data = $this->rows['body'];

      foreach ($data as $__row__ => $rows) {
         foreach ($rows as $__column__ => $data) {
               if ($column === $__column__ && $value === $data) {
                  return true;
               }
         }
      }

      return false;
   }
}
