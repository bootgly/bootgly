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


use function count;
use function implode;
use BackedEnum;
use InvalidArgumentException;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Capabilities;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Change;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Column;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Index;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Reference;
use Bootgly\ADI\Databases\SQL\Schema\Dialect;


/**
 * SQLite DDL compiler.
 */
class SQLite extends Dialect
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Check if SQLite supports one schema feature capability.
    */
   public function check (Capabilities $Capability): bool
   {
      return match ($Capability) {
         Capabilities::AddConstraint,
         Capabilities::AlterColumnDefault,
         Capabilities::AlterColumnNullability,
         Capabilities::AlterColumnType,
         Capabilities::AlterColumnUsing,
         Capabilities::DropConstraint,
         Capabilities::MultiActionAlter => false,
         default => true,
      };
   }

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
      if ($Blueprint->changes !== []) {
         foreach ($Blueprint->changes as $Change) {
            if ($Change->expression !== null) {
               $this->guard(Capabilities::AlterColumnUsing);
            }

            if ($Change->nullable !== null) {
               $this->guard(Capabilities::AlterColumnNullability);
            }

            if ($Change->defaulted || $Change->dropped) {
               $this->guard(Capabilities::AlterColumnDefault);
            }

            if ($Change->typed) {
               $this->guard(Capabilities::AlterColumnType);
            }
         }
      }

      if ($Blueprint->references !== []) {
         $this->guard(Capabilities::AddConstraint);
      }

      $actions = [];

      foreach ($Blueprint->columns as $Column) {
         $definition = $this->define($Column);
         $actions[] = "ADD COLUMN {$definition}";
      }

      foreach ($Blueprint->renames as $Rename) {
         $this->guard(Capabilities::RenameColumn);
         $from = $this->quote($Rename->from);
         $to = $this->quote($Rename->to);
         $actions[] = "RENAME COLUMN {$from} TO {$to}";
      }

      foreach ($Blueprint->drops as $drop) {
         $this->guard(Capabilities::DropColumn);
         $column = $this->quote($drop);
         $actions[] = "DROP COLUMN {$column}";
      }

      if ($actions === []) {
         throw new InvalidArgumentException('ALTER TABLE requires at least one schema action.');
      }

      if (count($actions) > 1) {
         $this->guard(Capabilities::MultiActionAlter);
      }

      $table = $this->quote($Blueprint->table);

      return new Query("ALTER TABLE {$table} {$actions[0]}");
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
    * Compile DROP INDEX.
    */
   public function unindex (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Name,
      bool $exists = true
   ): Query
   {
      $clause = $exists ? 'IF EXISTS ' : '';
      $name = $this->quote($Name);

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
      $this->fail(Capabilities::DropConstraint);
   }

   /**
    * Compile one column definition.
    */
   private function define (Column $Column): string
   {
      $generated = $Column->generated && $Column->primary;
      $segments = [
         $this->quote($Column->name),
         $generated ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : $this->cast($Column),
      ];

      if ($Column->nullable === false && $generated === false) {
         $segments[] = 'NOT NULL';
      }

      if ($Column->defaulted && $generated === false) {
         $default = $this->escape($Column->default);
         $segments[] = "DEFAULT {$default}";
      }

      if ($Column->primary && $generated === false) {
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
         Types::BigInteger => 'INTEGER',
         Types::Boolean => 'INTEGER',
         Types::Date => 'TEXT',
         Types::Decimal => 'NUMERIC',
         Types::Float => 'REAL',
         Types::Integer => 'INTEGER',
         Types::Json => 'TEXT',
         Types::JsonB => 'TEXT',
         Types::String => 'TEXT',
         Types::Text => 'TEXT',
         Types::Time => 'TEXT',
         Types::Timestamp => 'TEXT',
         Types::Uuid => 'TEXT',
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
}
