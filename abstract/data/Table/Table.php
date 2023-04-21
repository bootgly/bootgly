<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Data;


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
   public function sum (int $column)
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
}
