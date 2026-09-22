<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema\Dialects;


use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_INT;
use function array_pop;
use function count;
use function explode;
use function filter_var;
use function implode;
use function is_int;
use function is_string;
use function max;
use function preg_match;
use function str_contains;
use function str_replace;
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

         if ($Change->typed) {
            $type = $this->cast($Change);
            $action = "ALTER COLUMN {$name} TYPE {$type}";

            if ($Change->expression !== null) {
               $expression = $Change->expression instanceof Expression
                  ? $Change->expression->SQL
                  : $Change->expression;
               $action = "{$action} USING {$expression}";
            }

            $actions[] = $action;
         }

         if ($Change->nullable !== null) {
            $action = $Change->nullable ? 'DROP NOT NULL' : 'SET NOT NULL';
            $actions[] = "ALTER COLUMN {$name} {$action}";
         }

         if ($Change->dropped) {
            $actions[] = "ALTER COLUMN {$name} DROP DEFAULT";
         }
         elseif ($Change->defaulted) {
            $value = $this->escape($Change->default);
            $actions[] = "ALTER COLUMN {$name} SET DEFAULT {$value}";
         }
      }

      // ! Kept apart from the action list on purpose — see the refusal below.
      $renames = [];

      foreach ($Blueprint->renames as $Rename) {
         $this->guard(Capabilities::RenameColumn);
         $from = $this->quote($Rename->from);
         $to = $this->quote($Rename->to);
         $renames[] = "RENAME COLUMN {$from} TO {$to}";
      }

      foreach ($Blueprint->drops as $drop) {
         $column = $this->quote($drop);
         $actions[] = "DROP COLUMN {$column}";
      }

      foreach ($Blueprint->references as $Reference) {
         $reference = $this->reference($Reference, true);
         $actions[] = "ADD {$reference}";
      }

      // ? PostgreSQL's ALTER TABLE has two disjoint forms: a comma-separated
      //   list of actions, and one rename. A rename belongs to the separable
      //   list in no version, so chaining it with anything — including a second
      //   rename — is a syntax error the server answers with, and this compiler
      //   emits one statement, so it cannot split them itself. Refused with the
      //   shape that works: the manual's own recipe was the failing combination.
      if ($renames !== [] && ($actions !== [] || count($renames) > 1)) {
         throw new InvalidArgumentException(
            'PostgreSQL renames a column in an ALTER TABLE of its own: give each rename '
            . 'its own alter(), with no other action beside it.'
         );
      }

      $actions = $renames === [] ? $actions : $renames;

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
    * Compile the resync of identity sequences left behind by one INSERT's explicit keys.
    *
    * An identity (or serial) column accepts an explicit value without advancing its sequence,
    * so the next generated id would collide with the written one. The statement moves each
    * sequence to the highest explicit integer key — only when the sequence is behind it: a
    * sequence already past the key when the statement runs is left untouched. Reading and moving
    * are two steps, so a concurrent insert into the same table can slip between them; seed while
    * nothing else writes to the seeded tables. The seeding role needs SELECT/USAGE and UPDATE on
    * the sequence; without them the statement fails.
    *
    * @param array<string,array<int,mixed>> $assignments
    */
   public function resync (string $table, array $assignments): null|Query
   {
      // ? A raw (`Expression`) table is not a quoted name `pg_get_serial_sequence` can parse
      if (preg_match('/^"(?:[^"]|"")+"(?:\."(?:[^"]|"")+")*$/', $table) !== 1) {
         return null;
      }

      // ! Highest explicit integer key per column
      $keys = [];
      foreach ($assignments as $column => $values) {
         // ? Only one quoted identifier names a column (an `Expression` key is raw SQL)
         if (preg_match('/^"((?:[^"]|"")+)"$/', $column, $matches) !== 1) {
            continue;
         }

         $name = str_replace('""', '"', $matches[1]);
         foreach ($values as $value) {
            $key = match (true) {
               is_int($value) => $value,
               is_string($value) => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
               default => null,
            };

            if ($key !== null) {
               $keys[$name] = max($keys[$name] ?? $key, $key);
            }
         }
      }

      // ?: No integer key was written — nothing can collide
      if ($keys === []) {
         return null;
      }

      // @ One (column, key) row per candidate — `pg_get_serial_sequence` yields NULL for a
      //   column without a sequence, which leaves its row inert
      $parameters = [$table];
      $rows = [];
      foreach ($keys as $name => $key) {
         // ! An all-digit column name came back as an int array key — bind it as text
         $parameters[] = (string) $name;
         $parameters[] = $key;
         $count = count($parameters);
         $rows[] = "({$this->Dialect->mark($count - 1)}, {$this->Dialect->mark($count)}::bigint)";
      }
      $values = implode(', ', $rows);
      $marker = $this->Dialect->mark(1);

      // : Forward-only — `nextval` runs only when the sequence is behind the key
      $SQL = implode(' ', [
         'SELECT setval("identity"."sequence", "keys"."key", true)',
         "FROM (VALUES {$values}) AS \"keys\" (\"column\", \"key\")",
         "CROSS JOIN LATERAL (SELECT pg_get_serial_sequence({$marker}, \"keys\".\"column\")::regclass AS \"sequence\") AS \"identity\"",
         'WHERE CASE WHEN pg_sequence_last_value("identity"."sequence") >= "keys"."key" THEN false',
         'ELSE nextval("identity"."sequence") <= "keys"."key" END',
      ]);

      return new Query($SQL, $parameters);
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
         $expression = $check instanceof Expression ? $check->SQL : $check;
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
         Types::Timestamptz => 'TIMESTAMPTZ',
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
