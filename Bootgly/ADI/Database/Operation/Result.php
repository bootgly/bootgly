<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database\Operation;


use function count;


/**
 * Database operation result.
 */
class Result
{
   // * Data
   public string $status;
   /** @var array<int,array<string,mixed>> */
   public array $rows;
   /** @var array<int,string> */
   public array $columns;
   public int $affected;

   // # Views
   /** @var array<string,mixed> */
   public array $row {
      get => $this->rows[0] ?? [];
   }

   public mixed $cell {
      get {
         foreach ($this->row as $value) {
            return $value;
         }

         return null;
      }
   }

   public int $count {
      get => count($this->rows);
   }

   public bool $empty {
      get => $this->rows === [];
   }

   // * Metadata
   // ...


   /**
    * Create a database result.
    *
    * @param array<int,array<string,mixed>> $rows
    * @param array<int,string> $columns
    */
   public function __construct (string $status = '', array $rows = [], array $columns = [], int $affected = 0)
   {
      // * Data
      $this->status = $status;
      $this->rows = $rows;
      $this->columns = $columns;
      $this->affected = $affected;
   }
}
