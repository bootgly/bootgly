<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema;


use Bootgly\ADI\Databases\SQL\Builder\Dialect as SQLDialect;
use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;


/**
 * Migration status table query factory.
 */
class Repository
{
   private const string MIGRATION = 'migration';
   private const string BATCH = 'batch';
   private const string CREATED_AT = 'created_at';

   // * Config
   public private(set) string $table;
   public SQLDialect $Dialect;

   // * Data
   // ...

   // * Metadata
   private Dialect $SchemaDialect;


   public function __construct (SQLDialect $Dialect, string $table)
   {
      // * Config
      $this->Dialect = $Dialect;
      $this->table = $table;
      $this->SchemaDialect = (new Dialects)->fetch($Dialect);
   }

   /**
    * Compile status table creation.
    */
   public function create (): Query
   {
      $Blueprint = new Blueprint($this->table);
      $Blueprint->add(self::MIGRATION, Types::String)
         ->limit(255)
         ->constrain(Keys::Primary);
      $Blueprint->add(self::BATCH, Types::Integer);
      $Blueprint->add(self::CREATED_AT, Types::Timestamp)
         ->default(new Expression('CURRENT_TIMESTAMP'));

      return $this->SchemaDialect->create($Blueprint, exists: true);
   }

   /**
    * Compile applied migrations fetch.
    */
   public function fetch (): Query
   {
      $table = $this->table();
      $migration = $this->column(self::MIGRATION);
      $batch = $this->column(self::BATCH);
      $created = $this->column(self::CREATED_AT);

      return new Query(
         "SELECT {$migration}, {$batch}, {$created} FROM {$table} ORDER BY {$batch} DESC, {$migration} DESC"
      );
   }

   /**
    * Compile current batch lookup.
    */
   public function peek (): Query
   {
      $table = $this->table();
      $batch = $this->column(self::BATCH);

      return new Query("SELECT COALESCE(MAX({$batch}), 0) AS {$batch} FROM {$table}");
   }

   /**
    * Compile migration insertion.
    */
   public function insert (string $migration, int $batch): Query
   {
      $table = $this->table();
      $name = $this->column(self::MIGRATION);
      $version = $this->column(self::BATCH);
      $first = $this->Dialect->mark(1);
      $second = $this->Dialect->mark(2);

      return new Query(
         "INSERT INTO {$table} ({$name}, {$version}) VALUES ({$first}, {$second})",
         [$migration, $batch]
      );
   }

   /**
    * Compile migration deletion.
    */
   public function delete (string $migration): Query
   {
      $table = $this->table();
      $name = $this->column(self::MIGRATION);
      $marker = $this->Dialect->mark(1);

      return new Query(
         "DELETE FROM {$table} WHERE {$name} = {$marker}",
         [$migration]
      );
   }

   /**
    * Quote the repository table identifier.
    */
   private function table (): string
   {
      return $this->Dialect->quote($this->table);
   }

   /**
    * Quote one repository column identifier.
    */
   private function column (string $name): string
   {
      return $this->Dialect->quote($name);
   }
}
