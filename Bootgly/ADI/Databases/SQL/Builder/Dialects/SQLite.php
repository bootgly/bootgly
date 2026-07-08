<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Builder\Dialects;


use function implode;
use function preg_replace_callback;
use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Capabilities;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Matches;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Nulls;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Dialect;


/**
 * SQLite SQL dialect compiler hooks.
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
    * Check if SQLite supports one feature capability.
    *
    * `RETURNING` stays disabled even on libsqlite ≥ 3.35: the `sqlite3`
    * extension steps such statements twice (an internal step + reset happens
    * inside `SQLite3::query()`/`SQLite3Stmt::execute()` before the fetch),
    * which would duplicate every write. Generated keys arrive through
    * `Result->inserted` instead.
    */
   public function check (Capabilities $Capability): bool
   {
      return $Capability !== Capabilities::Output;
   }

   /**
    * Quote one SQLite identifier name.
    */
   public function quote (string $name): string
   {
      return $this->segment($name, '"', '"', '""');
   }

   /**
    * Mark one SQLite positional parameter.
    */
   public function mark (int $position): string
   {
      return "?{$position}";
   }

   /**
    * Rebase SQLite numbered placeholders in one compiled subquery.
    */
   public function rebase (string $sql, int $offset): string
   {
      $sql = preg_replace_callback(
         '/\?(\d+)/',
         fn (array $matches): string => $this->mark($offset + (int) $matches[1]),
         $sql
      );

      if ($sql === null) {
         throw new InvalidArgumentException('SQL subquery placeholders could not be rewritten.');
      }

      return $sql;
   }

   /**
    * Compile one SQLite ORDER BY expression.
    */
   public function order (string $column, Orders $Order, null|Nulls $Nulls = null): string
   {
      $nulls = $Nulls === null ? '' : " {$Nulls->value}";

      return "{$column} {$Order->value}{$nulls}";
   }

   /**
    * Compile SQLite ON CONFLICT handling when configured.
    *
    * @param array<string,array<int,mixed>> $assignments
    * @param array<int,string> $conflicts
    */
   public function upsert (array $assignments, array $conflicts): string
   {
      return $this->resolve($assignments, $conflicts);
   }

   /**
    * Compile one SQLite text matching predicate.
    */
   public function match (string $column, string $placeholder, Matches $Match): string
   {
      return match ($Match) {
         Matches::Insensitive => "{$column} LIKE {$placeholder} COLLATE NOCASE",
         Matches::Like => "{$column} LIKE {$placeholder}",
         Matches::Text => "{$column} MATCH {$placeholder}",
      };
   }

   /**
    * Append SQLite RETURNING columns.
    *
    * @param array<int,string> $columns
    */
   public function output (string $sql, array $columns): string
   {
      if ($columns === []) {
         return $sql;
      }

      $output = implode(', ', $columns);

      return "{$sql} RETURNING {$output}";
   }
}
