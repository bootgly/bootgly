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


use BackedEnum;
use Stringable;


/**
 * SQL builder identifier aliasing helpers.
 */
trait Aliasable
{
   /**
    * Render one aliased identifier reference when present.
    */
   abstract private function target (string $identifier, bool $exact = true): string;

   /**
    * Quote one enum or object identifier.
    */
   private function identify (BackedEnum|Stringable $Identifier): string
   {
      if ($Identifier instanceof Expression) {
         return $Identifier->sql;
      }

      return Identifier::quote($Identifier, $this->Dialect);
   }

   /**
    * Quote a list of enum or object identifiers.
    *
    * @param array<array-key,BackedEnum|Stringable> $Identifiers
    *
    * @return array<int,string>
    */
   private function quote (array $Identifiers): array
   {
      $quoted = [];

      foreach ($Identifiers as $Identifier) {
         $quoted[] = $this->identify($Identifier);
      }

      return $quoted;
   }

   /**
    * Render aliased identifiers when present.
    */
   private function render (string $identifier, bool $table = false): string
   {
      $alias = $table
         ? $this->tableAliases[$identifier] ?? null
         : $this->columnAliases[$identifier] ?? $this->expressionAliases[$identifier] ?? null;
      $target = $this->target($identifier, exact: false);

      if ($alias === null) {
         return $target;
      }

      return "{$target} AS {$alias}";
   }

   /**
    * Render a list of aliased identifiers when present.
    *
    * @param array<int,string> $identifiers
    *
    * @return array<int,string>
    */
   private function map (array $identifiers): array
   {
      $rendered = [];

      foreach ($identifiers as $identifier) {
         $rendered[] = $this->render($identifier);
      }

      return $rendered;
   }

   /**
    * Render a list of aliased identifier references when present.
    *
    * @param array<int,string> $identifiers
    *
    * @return array<int,string>
    */
   private function refer (array $identifiers): array
   {
      $rendered = [];

      foreach ($identifiers as $identifier) {
         $rendered[] = $this->target($identifier);
      }

      return $rendered;
   }

   /**
    * Promote a pending column alias into a table alias.
    */
   private function promote (string $table): void
   {
      if (isset($this->columnAliases[$table]) === false) {
         return;
      }

      $this->tableAliases[$table] = $this->columnAliases[$table];
      unset($this->columnAliases[$table]);
   }
}
