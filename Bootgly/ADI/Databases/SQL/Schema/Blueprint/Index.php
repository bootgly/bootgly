<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema\Blueprint;


use function implode;


/**
 * Table index definition.
 */
class Index
{
   // * Config
   public private(set) string $table;
   /** @var array<int,string> */
   public private(set) array $columns;
   public private(set) string $name;
   public private(set) bool $unique;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param array<int,string> $columns
    */
   public function __construct (string $table, array $columns, null|string $name = null, bool $unique = false)
   {
      // * Config
      $this->table = $table;
      $this->columns = $columns;
      $this->name = $name ?? $this->build($table, $columns, $unique);
      $this->unique = $unique;
   }

   /**
    * Build the conventional index name.
    *
    * @param array<int,string> $columns
    */
   private function build (string $table, array $columns, bool $unique): string
   {
      $type = $unique ? 'unique' : 'index';
      $columns = implode('_', $columns);

      return Identifier::limit("{$table}_{$columns}_{$type}");
   }
}
