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
 * MySQL DDL compiler.
 */
class MySQL extends Dialect
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Check if MySQL supports one schema feature capability.
    */
   public function check (Capabilities $Capability): bool
   {
      return match ($Capability) {
         Capabilities::AlterColumnNullability,
         Capabilities::AlterColumnUsing => false,
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
      $actions = [];

      foreach ($Blueprint->columns as $Column) {
         $definition = $this->define($Column);
         $actions[] = "ADD COLUMN {$definition}";
      }

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

         $name = $this->quote($Change->name);

         if ($Change->typed) {
            $this->guard(Capabilities::AlterColumnType);
            $type = $this->cast($Change);
            $actions[] = "MODIFY COLUMN {$name} {$type}";
         }

         if ($Change->dropped) {
            $actions[] = "ALTER COLUMN {$name} DROP DEFAULT";

            continue;
         }

         if ($Change->defaulted) {
            $value = $this->escape($Change->default);
            $actions[] = "ALTER COLUMN {$name} SET DEFAULT {$value}";
         }
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

      foreach ($Blueprint->references as $Reference) {
         $this->guard(Capabilities::AddConstraint);
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

      return new Query("RENAME TABLE {$from} TO {$to}");
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
      $name = $this->quote($Name);
      $table = $this->quote($Table);

      return new Query("DROP INDEX {$name} ON {$table}");
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
      $this->guard(Capabilities::DropConstraint);

      $table = $this->quote($Table);
      $name = $this->quote($Name);

      return new Query("ALTER TABLE {$table} DROP FOREIGN KEY {$name}");
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

      if ($Column->nullable === false) {
         $segments[] = 'NOT NULL';
      }

      if ($Column->generated) {
         $segments[] = 'AUTO_INCREMENT';
      }

      if ($Column->defaulted) {
         $default = $this->escape($Column->default);
         $segments[] = "DEFAULT {$default}";
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
            ? "DECIMAL({$Column->precision}, {$Column->scale})"
            : 'DECIMAL',
         Types::Float => 'DOUBLE',
         Types::Integer => 'INT',
         Types::Json => 'JSON',
         Types::JsonB => 'JSON',
         Types::String => "VARCHAR({$Column->length})",
         Types::Text => 'LONGTEXT',
         Types::Time => 'TIME',
         Types::Timestamp => 'TIMESTAMP',
         Types::Uuid => 'CHAR(36)',
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
