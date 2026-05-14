<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Builder;


use function explode;
use function implode;
use function in_array;
use function str_replace;
use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Capabilities;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Matches;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Nulls;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;


/**
 * SQL dialect compiler contract.
 */
abstract class Dialect
{
   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Check if this dialect supports one feature capability.
    */
   public function check (Capabilities $Capability): bool
   {
      return true;
   }

   /**
    * Segment and quote one dotted identifier.
    */
   protected function segment (string $name, string $open, string $close, string $escape): string
   {
      $parts = explode('.', $name);
      $quoted = [];

      foreach ($parts as $part) {
         if ($part === '') {
            throw new InvalidArgumentException('SQL identifier segment cannot be empty.');
         }

         if ($part === '*') {
            $quoted[] = '*';

            continue;
         }

         $part = str_replace($close, $escape, $part);
         $quoted[] = "{$open}{$part}{$close}";
      }

      return implode('.', $quoted);
   }

   /**
    * Resolve PostgreSQL/SQLite ON CONFLICT handling when configured.
    *
    * @param array<string,array<int,mixed>> $assignments
    * @param array<int,string> $conflicts
    */
   protected function resolve (array $assignments, array $conflicts, string $source = 'EXCLUDED'): string
   {
      if ($conflicts === []) {
         return '';
      }

      $target = implode(', ', $conflicts);
      $updates = [];

      foreach ($assignments as $column => $_) {
         if (in_array($column, $conflicts, true)) {
            continue;
         }

         $updates[] = "{$column} = {$source}.{$column}";
      }

      if ($updates === []) {
         return " ON CONFLICT ({$target}) DO NOTHING";
      }

      $assignments = implode(', ', $updates);

      return " ON CONFLICT ({$target}) DO UPDATE SET {$assignments}";
   }

   /**
    * Quote one SQL identifier name.
    */
   abstract public function quote (string $name): string;

   /**
    * Mark one positional parameter.
    */
   abstract public function mark (int $position): string;

   /**
    * Rebase placeholders in one compiled subquery by an offset.
    */
   abstract public function rebase (string $sql, int $offset): string;

   /**
    * Compile one ORDER BY expression.
    */
   abstract public function order (string $column, Orders $Order, null|Nulls $Nulls = null): string;

   /**
    * Compile one dialect-specific upsert clause.
    *
    * @param array<string,array<int,mixed>> $assignments
    * @param array<int,string> $conflicts
    */
   abstract public function upsert (array $assignments, array $conflicts): string;

   /**
    * Compile one text matching predicate.
    */
   abstract public function match (string $column, string $placeholder, Matches $Match): string;

   /**
    * Append mutation output columns.
    *
    * @param array<int,string> $columns
    */
   abstract public function output (string $sql, array $columns): string;
}
