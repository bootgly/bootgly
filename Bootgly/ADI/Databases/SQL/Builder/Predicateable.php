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


use function array_values;
use function count;
use function implode;
use function is_array;
use Closure;
use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Junctions;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Matches;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;


/**
 * SQL builder predicate composition helpers.
 *
 * @phpstan-type Predicate array{column:string,operator:Operators|Matches,value:mixed,junction:Junctions}
 * @phpstan-type PredicateGroup array{filters:array<int,array<string,mixed>>,junction:Junctions}
 */
trait Predicateable
{
   /**
    * Bind one parameter value.
    *
    * @param array<int,mixed> $parameters
    */
   abstract private function bind (array &$parameters, mixed $value): string;

   /**
    * Embed and merge one subquery.
    *
    * @param array<int,mixed> $parameters
    */
   abstract private function embed (self|Query $query, array &$parameters): string;

   /**
    * Spawn one nested builder.
    */
   abstract private function spawn (): self;

   /**
    * Render one aliased identifier reference when present.
    */
   abstract private function target (string $identifier, bool $exact = true): string;

   /**
    * Join all filters into one SQL predicate.
    *
    * @param array<int,Predicate|PredicateGroup> $Predicates
    * @param array<int,mixed> $parameters
    */
   private function combine (array $Predicates, array &$parameters, bool $exact = false): string
   {
      $filters = [];

      foreach ($Predicates as $index => $Filter) {
         $prefix = $index > 0 ? "{$Filter['junction']->value} " : '';

         if (isset($Filter['filters'])) {
            /** @var array<int,Predicate|PredicateGroup> $nested */
            $nested = $Filter['filters'];
            $group = $this->combine($nested, $parameters, $exact);

            if ($group === '') {
               continue;
            }

            $filters[] = "{$prefix}({$group})";

            continue;
         }

         $filters[] = "{$prefix}{$this->compare($Filter, $parameters, $exact)}";
      }

      return implode(' ', $filters);
   }

   /**
    * Compile one filter comparison.
    *
    * @param array{column:string,operator:Operators|Matches,value:mixed,junction:Junctions} $Filter
    * @param array<int,mixed> $parameters
    */
   private function compare (array $Filter, array &$parameters, bool $exact = false): string
   {
      $column = $this->target($Filter['column'], $exact);
      $Operator = $Filter['operator'];
      $value = $Filter['value'];

      if ($Operator instanceof Matches) {
         $placeholder = $this->bind($parameters, $value);

         return $this->Dialect->match($column, $placeholder, $Operator);
      }

      if ($Operator === Operators::Between) {
         if (is_array($value) === false || count($value) !== 2) {
            throw new InvalidArgumentException('SQL BETWEEN filter requires exactly two values.');
         }

         $values = array_values($value);
         $start = $this->bind($parameters, $values[0]);
         $end = $this->bind($parameters, $values[1]);

         return "{$column} BETWEEN {$start} AND {$end}";
      }

      if ($Operator === Operators::In) {
         if ($value instanceof self || $value instanceof Query) {
            $query = $this->embed($value, $parameters);

            return "{$column} IN ({$query})";
         }

         if (is_array($value) === false || $value === []) {
            throw new InvalidArgumentException('SQL IN filter requires a non-empty array value.');
         }

         $placeholders = [];

         foreach ($value as $item) {
            $placeholders[] = $this->bind($parameters, $item);
         }

         $values = implode(', ', $placeholders);

         return "{$column} IN ({$values})";
      }

      if (
         $Operator === Operators::IsFalse
         || $Operator === Operators::IsNotNull
         || $Operator === Operators::IsNull
         || $Operator === Operators::IsTrue
      ) {
         return "{$column} {$Operator->value}";
      }

      $placeholder = $this->bind($parameters, $value);

      return "{$column} {$Operator->value} {$placeholder}";
   }

   /**
    * Build one nested filter group.
    *
    * @return PredicateGroup
    */
   private function scope (Closure $Scope, Junctions $Junction): array
   {
      $Group = $this->spawn();
      $Scope($Group);

      if ($Group->filters === []) {
         throw new InvalidArgumentException('SQL nested filter requires at least one filter.');
      }

      return [
         'filters' => $Group->filters,
         'junction' => $Junction,
      ];
   }

   /**
    * Validate one filter value as early as possible.
    */
   private function validate (Operators $Operator, mixed $value): void
   {
      if ($Operator === Operators::Between && (is_array($value) === false || count($value) !== 2)) {
         throw new InvalidArgumentException('SQL BETWEEN filter requires exactly two values.');
      }

      if ($Operator === Operators::In && ($value instanceof self || $value instanceof Query)) {
         return;
      }

      if ($Operator === Operators::In && (is_array($value) === false || $value === [])) {
         throw new InvalidArgumentException('SQL IN filter requires a non-empty array value.');
      }

      if (
         (
            $Operator === Operators::IsFalse
            || $Operator === Operators::IsNotNull
            || $Operator === Operators::IsNull
            || $Operator === Operators::IsTrue
         )
         && $value !== null
      ) {
         throw new InvalidArgumentException('SQL literal filters do not accept values.');
      }
   }
}
