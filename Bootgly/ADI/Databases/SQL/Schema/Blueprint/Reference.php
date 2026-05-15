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


use function str_replace;

use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\References;


/**
 * Foreign key reference definition.
 */
class Reference
{
   // * Config
   public private(set) string $origin;
   public private(set) string $source;
   public private(set) string $table;
   public private(set) string $column;
   public private(set) string $name;

   // * Data
   public private(set) null|References $Delete = null;
   public private(set) null|References $Update = null;

   // * Metadata
   // ...


   public function __construct (
      string $origin,
      string $source,
      string $table,
      string $column = 'id',
      null|string $name = null
   )
   {
      // * Config
      $this->origin = $origin;
      $this->source = $source;
      $this->table = $table;
      $this->column = $column;
      $this->name = $name ?? $this->build($origin, $source, $table);
   }

   /**
    * Set the ON DELETE action.
    */
   public function delete (References $Reference): self
   {
      $this->Delete = $Reference;

      return $this;
   }

   /**
    * Set the ON UPDATE action.
    */
   public function update (References $Reference): self
   {
      $this->Update = $Reference;

      return $this;
   }

   /**
    * Build the conventional foreign key constraint name.
    */
   private function build (string $origin, string $source, string $table): string
   {
      $origin = str_replace('.', '_', $origin);
      $source = str_replace('.', '_', $source);
      $table = str_replace('.', '_', $table);

      return Identifier::limit("{$origin}_{$source}_{$table}_fk");
   }
}
