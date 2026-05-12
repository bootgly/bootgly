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


/**
 * Database operation result.
 */
class Result
{
   // * Config
   public string $status;

   // * Data
   /** @var array<int,array<string,mixed>> */
   public array $rows;
   /** @var array<int,string> */
   public array $columns;
   public int $affected;

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
      // * Config
      $this->status = $status;

      // * Data
      $this->rows = $rows;
      $this->columns = $columns;
      $this->affected = $affected;
   }
}
