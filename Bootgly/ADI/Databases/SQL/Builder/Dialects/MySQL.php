<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Builder\Dialects;


use function array_key_first;
use function implode;
use function in_array;
use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Capabilities;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Matches;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Nulls;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Dialect;


/**
 * MySQL SQL dialect compiler hooks.
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
    * Check if MySQL supports one feature capability.
    */
   public function check (Capabilities $Capability): bool
   {
      return $Capability !== Capabilities::Output;
   }

   /**
    * Quote one MySQL identifier name.
    */
   public function quote (string $name): string
   {
      return $this->segment($name, '`', '`', '``');
   }

   /**
    * Mark one MySQL positional parameter.
    */
   public function mark (int $position): string
   {
      return '?';
   }

   /**
    * MySQL anonymous placeholders do not require SQL text rebasing.
    */
   public function rebase (string $sql, int $offset): string
   {
      return $sql;
   }

   /**
    * Compile one MySQL ORDER BY expression.
    */
   public function order (string $column, Orders $Order, null|Nulls $Nulls = null): string
   {
      if ($Nulls === Nulls::First) {
         return "{$column} IS NOT NULL ASC, {$column} {$Order->value}";
      }

      if ($Nulls === Nulls::Last) {
         return "{$column} IS NULL ASC, {$column} {$Order->value}";
      }

      return "{$column} {$Order->value}";
   }

   /**
    * Compile MySQL ON DUPLICATE KEY handling when configured.
    *
    * @param array<string,array<int,mixed>> $assignments
    * @param array<int,string> $conflicts
    */
   public function upsert (array $assignments, array $conflicts): string
   {
      if ($conflicts === []) {
         return '';
      }

      $updates = [];

      foreach ($assignments as $column => $_) {
         if (in_array($column, $conflicts, true)) {
            continue;
         }

         $updates[] = "{$column} = VALUES({$column})";
      }

      if ($updates === []) {
         $column = array_key_first($assignments);

         if ($column === null) {
            return '';
         }

         $updates[] = "{$column} = {$column}";
      }

      $assignments = implode(', ', $updates);

      return " ON DUPLICATE KEY UPDATE {$assignments}";
   }

   /**
    * Compile one MySQL text matching predicate.
    */
   public function match (string $column, string $placeholder, Matches $Match): string
   {
      return match ($Match) {
         Matches::Insensitive => "LOWER({$column}) LIKE LOWER({$placeholder})",
         Matches::Like => "{$column} LIKE {$placeholder}",
         Matches::Text => "MATCH ({$column}) AGAINST ({$placeholder} IN NATURAL LANGUAGE MODE)",
      };
   }

   /**
    * MySQL does not support the canonical RETURNING path used by this builder.
    *
    * @param array<int,string> $columns
    */
   public function output (string $sql, array $columns): string
   {
      if ($columns === []) {
         return $sql;
      }

      throw new InvalidArgumentException('SQL dialect does not support RETURNING output.');
   }
}
