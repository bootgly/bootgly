<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema\Dialects;


use function array_pop;
use function explode;
use function implode;
use function str_contains;
use BackedEnum;
use InvalidArgumentException;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Change;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Column;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Index;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Reference;
use Bootgly\ADI\Databases\SQL\Schema\Dialect;


/**
 * PostgreSQL DDL compiler.
 */
class PostgreSQL extends Dialect
{
   // * Config
   public bool $transactions = true;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Compile CREATE TABLE.
    */
   public function create (Blueprint $Blueprint, bool $exists = false): Query
   {
      if ($Blueprint->columns === [] && $Blueprint->references === []) {
         throw new InvalidArgumentException('CREATE TABLE requires at least one column or constraint.');
      }

      $definitions = [];

      foreach ($Blueprint->columns as $Column) {
         $definitions[] = $this->define($Column);
      }

      foreach ($Blueprint->references as $Reference) {
         $definitions[] = $this->reference($Reference, true);
      }

      $clause = $exists ? 'IF NOT EXISTS ' : '';
      $table = $this->quote($Blueprint->table);
      $definition = implode(', ', $definitions);

      return new Query("CREATE TABLE {$clause}{$table} ({$definition})");
   }

   /**
    * Compile ALTER TABLE.
    */
   public function alter (Blueprint $Blueprint): Query
   {
      $actions = [];

      foreach ($Blueprint->columns as $Column) {
         $definition = $this->define($Column);
         $actions[] = "ADD COLUMN {$definition}";
      }

      foreach ($Blueprint->changes as $Change) {
         $name = $this->quote($Change->name);
         $type = $this->cast($Change);
         $action = "ALTER COLUMN {$name} TYPE {$type}";

         if ($Change->expression !== null) {
            $expression = $Change->expression instanceof Expression
               ? $Change->expression->sql
               : $Change->expression;
            $action = "{$action} USING {$expression}";
         }

         $actions[] = $action;
      }

      foreach ($Blueprint->renames as $Rename) {
         $from = $this->quote($Rename->from);
         $to = $this->quote($Rename->to);
         $actions[] = "RENAME COLUMN {$from} TO {$to}";
      }

      foreach ($Blueprint->nullabilities as $Nullability) {
         $name = $this->quote($Nullability->name);
         $action = $Nullability->nullable ? 'DROP NOT NULL' : 'SET NOT NULL';
         $actions[] = "ALTER COLUMN {$name} {$action}";
      }

      foreach ($Blueprint->defaults as $Default) {
         $name = $this->quote($Default->name);

         if ($Default->dropped) {
            $actions[] = "ALTER COLUMN {$name} DROP DEFAULT";

            continue;
         }

         $value = $this->escape($Default->value);
         $actions[] = "ALTER COLUMN {$name} SET DEFAULT {$value}";
      }

      foreach ($Blueprint->drops as $drop) {
         $column = $this->quote($drop);
         $actions[] = "DROP COLUMN {$column}";
      }

      foreach ($Blueprint->references as $Reference) {
         $reference = $this->reference($Reference, true);
         $actions[] = "ADD {$reference}";
      }

      if ($actions === []) {
         throw new InvalidArgumentException('ALTER TABLE requires at least one schema action.');
      }

      $table = $this->quote($Blueprint->table);
      $action = implode(', ', $actions);

      return new Query("ALTER TABLE {$table} {$action}");
   }

   /**
    * Compile DROP TABLE.
    */
   public function drop (BackedEnum|Stringable|string $Table, bool $exists = true): Query
   {
      $clause = $exists ? 'IF EXISTS ' : '';
      $table = $this->quote($Table);

      return new Query("DROP TABLE {$clause}{$table}");
   }

   /**
    * Compile RENAME TABLE.
      */
   public function rename (BackedEnum|Stringable|string $From, BackedEnum|Stringable|string $To): Query
   {
      $from = $this->quote($From);
      $to = $this->quote($To);

      return new Query("ALTER TABLE {$from} RENAME TO {$to}");
   }

   /**
    * Compile CREATE INDEX.
    */
   public function index (Index $Index): Query
   {
      $unique = $Index->unique ? 'UNIQUE ' : '';
      $name = $this->quote($Index->name);
      $table = $this->quote($Index->table);
      $columns = [];

      foreach ($Index->columns as $column) {
         $columns[] = $this->quote($column);
      }
      $columns = implode(', ', $columns);

      return new Query("CREATE {$unique}INDEX {$name} ON {$table} ({$columns})");
   }

   /**
    * Compile PostgreSQL advisory lock acquisition.
    */
   public function lock (int $key): Query
   {
      $marker = $this->Dialect->mark(1);

      return new Query("SELECT pg_try_advisory_lock({$marker}) AS \"locked\"", [$key]);
   }

   /**
    * Compile PostgreSQL advisory lock release.
    */
   public function unlock (int $key): Query
   {
      $marker = $this->Dialect->mark(1);

      return new Query("SELECT pg_advisory_unlock({$marker}) AS \"unlocked\"", [$key]);
   }

   /**
    * Compile DROP INDEX.
    *
    * PostgreSQL indexes are schema-scoped; the table argument supplies schema
    * qualification when passed as schema.table.
    */
   public function unindex (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Name,
      bool $exists = true
   ): Query
   {
      $clause = $exists ? 'IF EXISTS ' : '';
      $name = $this->qualify($Table, $Name);

      return new Query("DROP INDEX {$clause}{$name}");
   }

   /**
    * Compile DROP CONSTRAINT.
    */
   public function unconstrain (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Name,
      bool $exists = true
   ): Query
   {
      $clause = $exists ? 'IF EXISTS ' : '';
      $table = $this->quote($Table);
      $name = $this->quote($Name);

      return new Query("ALTER TABLE {$table} DROP CONSTRAINT {$clause}{$name}");
   }

   /**
    * Compile one column definition.
    */
   private function define (Column $Column): string
   {
      $segments = [
         $this->quote($Column->name),
         $this->cast($Column),
      ];

      if ($Column->generated) {
         $segments[] = 'GENERATED BY DEFAULT AS IDENTITY';
      }

      if ($Column->nullable === false) {
         $segments[] = 'NOT NULL';
      }

      if ($Column->defaulted) {
         $segments[] = 'DEFAULT ' . $this->escape($Column->default);
      }

      if ($Column->primary) {
         $segments[] = 'PRIMARY KEY';
      }

      if ($Column->unique) {
         $segments[] = 'UNIQUE';
      }

      foreach ($Column->checks as $check) {
         $expression = $check instanceof Expression ? $check->sql : $check;
         $segments[] = "CHECK ({$expression})";
      }

      if ($Column->Reference !== null) {
         $segments[] = $this->reference($Column->Reference, false);
      }

      return implode(' ', $segments);
   }

   /**
    * Compile one column type.
    */
   private function cast (Column|Change $Column): string
   {
      return match ($Column->Type) {
         Types::BigInteger => 'BIGINT',
         Types::Boolean => 'BOOLEAN',
         Types::Date => 'DATE',
         Types::Decimal => $Column->precision > 0
            ? "NUMERIC({$Column->precision}, {$Column->scale})"
            : 'NUMERIC',
         Types::Float => 'DOUBLE PRECISION',
         Types::Integer => 'INTEGER',
         Types::Json => 'JSON',
         Types::JsonB => 'JSONB',
         Types::String => "VARCHAR({$Column->length})",
         Types::Text => 'TEXT',
         Types::Time => 'TIME',
         Types::Timestamp => 'TIMESTAMP',
         Types::Uuid => 'UUID',
      };
   }

   /**
    * Compile one foreign key reference.
    */
   private function reference (Reference $Reference, bool $table = false): string
   {
      $column = $this->quote($Reference->column);

      $segments = [
         'REFERENCES',
         $this->quote($Reference->table),
         "({$column})",
      ];

      if ($Reference->Delete !== null) {
         $action = $this->refer($Reference->Delete);
         $segments[] = "ON DELETE {$action}";
      }

      if ($Reference->Update !== null) {
         $action = $this->refer($Reference->Update);
         $segments[] = "ON UPDATE {$action}";
      }

      $target = implode(' ', $segments);

      if ($table === false) {
         return $target;
      }

      $constraint = $this->quote($Reference->name);
      $name = "CONSTRAINT {$constraint} ";
      $source = $this->quote($Reference->source);

      return "{$name}FOREIGN KEY ({$source}) {$target}";
   }

   /**
    * Qualify one index name with the table schema when available.
    */
   private function qualify (BackedEnum|Stringable|string $Table, BackedEnum|Stringable|string $Name): string
   {
      $name = $this->normalize($Name);

      if (str_contains($name, '.')) {
         return $this->quote($name);
      }

      $table = $this->normalize($Table);
      if (str_contains($table, '.') === false) {
         return $this->quote($name);
      }

      $segments = explode('.', $table);
      array_pop($segments);
      $schema = implode('.', $segments);

      return $this->quote("{$schema}.{$name}");
   }
}
