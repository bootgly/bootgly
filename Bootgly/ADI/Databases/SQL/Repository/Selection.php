<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


use function array_key_first;
use function array_slice;
use function count;
use function is_string;
use BackedEnum;
use Closure;
use InvalidArgumentException;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Junctions;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Locks;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Matches;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Nulls;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Dialect;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model;


/**
 * ORM query selection compiled through the SQL builder.
 */
class Selection
{
   // * Config
   public private(set) Model $Model;
   public private(set) Dialect $Dialect;

   // * Data
   /** @var array<int,string> */
   public private(set) array $loads = [];
   /** @var array<int,string> */
   public private(set) array $scopes = [];
   /** @var array<int,array{order:Orders,column:BackedEnum|Stringable,nulls:null|Nulls}> */
   public private(set) array $orders = [];

   // * Metadata
   // @ The caller's predicates, in call order: a filter, a text match, or a
   //   nested group.
   /** @var array<int,array{column:BackedEnum|Stringable,operator:Operators,value:mixed,junction:Junctions}|array{column:BackedEnum|Stringable,value:mixed,match:Matches,junction:Junctions}|array{nest:Closure,junction:Junctions}> */
   private array $predicates = [];
   // @ Predicates the repository adds of its own — each named scope, each
   //   `Selecting` hook, the cursor keyset — one group each. Every group is
   //   ANDed after the caller's predicates as a whole, so a caller's `OR`
   //   can never reach past it.
   /** @var array<int,array<int,array{column:BackedEnum|Stringable,operator:Operators,value:mixed,junction:Junctions}|array{column:BackedEnum|Stringable,value:mixed,match:Matches,junction:Junctions}|array{nest:Closure,junction:Junctions}>> */
   private array $guards = [];
   private null|int $limited = null;
   private int $offset = 0;
   private null|Locks $Lock = null;


   public function __construct (Model $Model, Dialect $Dialect)
   {
      // * Config
      $this->Model = $Model;
      $this->Dialect = $Dialect;
   }

   /**
    * Compile this ORM selection to a SQL query.
    */
   public function compile (): Query
   {
      $Builder = new Builder($this->Dialect);
      $Builder->table(new Identifier($this->Model->table));

      foreach ($this->Model->columns as $column => $_) {
         $Builder->select(new Identifier($column));
      }

      $this->restrict($Builder);

      foreach ($this->orders as $Order) {
         $Builder->order(
            $Order['order'],
            $this->identify($Order['column']),
            $Order['nulls']
         );
      }

      if ($this->limited !== null) {
         $Builder->limit($this->limited, $this->offset);
      }
      else if ($this->offset > 0) {
         $Builder->skip($this->offset);
      }

      if ($this->Lock !== null) {
         $Builder->lock($this->Lock);
      }

      return $Builder->compile();
   }

   /**
    * Compile this ORM selection to a SQL total count query.
    *
    * Only restriction predicates (filters, matches) are replayed — order,
    * limit, offset and locks do not affect the total.
    */
   public function count (): Query
   {
      $Builder = new Builder($this->Dialect);
      $Builder->table(new Identifier($this->Model->table));
      $Builder->count(new Identifier('total'));

      $this->restrict($Builder);

      // : Total count query.
      return $Builder->compile();
   }

   /**
    * Add one parameterized predicate.
    */
   public function filter (BackedEnum|Stringable $Column, Operators $Operator, mixed $value = null, Junctions $Junction = Junctions::And): static
   {
      $this->predicates[] = [
         'column' => $Column,
         'operator' => $Operator,
         'value' => $value,
         'junction' => $Junction,
      ];

      return $this;
   }

   /**
    * Add the predicates one closure adds as a group of their own, ANDed after
    * the caller's predicates as a whole.
    *
    * The repository runs every named scope and every `Selecting` hook through
    * here: whatever `filter()` / `match()` calls the closure makes are taken
    * out of the caller's predicates into one group, so neither a caller's
    * `OR` nor one inside the closure can loosen the other. A closure that
    * adds nothing adds no group.
    */
   public function guard (Closure $Guard): static
   {
      $from = count($this->predicates);

      // ! Moved even when the closure throws: a predicate it added before the
      //   throw must not stay among the caller's, where an `OR` reaches past it.
      try {
         $Guard($this);
      }
      finally {
         $added = array_slice($this->predicates, $from);

         if ($added !== []) {
            $this->predicates = array_slice($this->predicates, 0, $from);
            $this->guards[] = $added;
         }
      }

      return $this;
   }

   /**
    * Register relation names to load explicitly after the root query.
    */
   public function load (string ...$relations): static
   {
      foreach ($relations as $relation) {
         $this->loads[] = $relation;
      }

      return $this;
   }

   /**
    * Lock selected rows where supported by the dialect.
    */
   public function lock (Locks $Lock): static
   {
      $this->Lock = $Lock;

      return $this;
   }

   /**
    * Limit selected row count and optional offset.
    */
   public function limit (int $count, int $offset = 0): static
   {
      $this->limited = $count;
      $this->offset = $offset;

      return $this;
   }

   /**
    * Add one text matching predicate.
    */
   public function match (BackedEnum|Stringable $Column, mixed $value, Matches $Match = Matches::Like, Junctions $Junction = Junctions::And): static
   {
      $this->predicates[] = [
         'column' => $Column,
         'value' => $value,
         'match' => $Match,
         'junction' => $Junction,
      ];

      return $this;
   }

   /**
    * Order selected rows by one mapped property or column.
    */
   public function order (Orders $Order, BackedEnum|Stringable $Column, null|Nulls $Nulls = null): static
   {
      $this->orders[] = [
         'order' => $Order,
         'column' => $Column,
         'nulls' => $Nulls,
      ];

      return $this;
   }

   /**
    * Register one named scope to apply before compilation.
    */
   public function scope (string $name): static
   {
      $this->scopes[] = $name;

      return $this;
   }

   /**
    * Restrict this selection to rows strictly after one keyset position.
    *
    * Orders and values are positional pairs — the last pair is the unique
    * tiebreak. Column names must be resolved SQL columns; the repository
    * resolves mapped properties before seeking.
    *
    * @param array<int,array{column:string,order:Orders}> $orders
    * @param array<int,bool|float|int|string> $values
    */
   public function seek (array $orders, array $values): static
   {
      // ?
      if ($orders === [] || count($orders) !== count($values)) {
         throw new InvalidArgumentException('ORM keyset seek requires matching order columns and values.');
      }

      // ! Keyset OR-chain: row i = equality on all preceding order columns
      //   plus one strict comparison on column i. Replay-safe for dialects.
      //   A group of its own: ANDed onto the caller's last predicate only, a
      //   caller's `OR` served the first page forever.
      $this->guards[] = [[
         'nest' => static function (Builder $Group) use ($orders, $values): void {
            foreach ($orders as $index => $Order) {
               $Group->nest(static function (Builder $Inner) use ($orders, $values, $index): void {
                  for ($previous = 0; $previous < $index; $previous++) {
                     $Inner->filter(new Identifier($orders[$previous]['column']), Operators::Equal, $values[$previous]);
                  }

                  $Operator = $orders[$index]['order'] === Orders::Asc
                     ? Operators::Greater
                     : Operators::Less;
                  $Inner->filter(new Identifier($orders[$index]['column']), $Operator, $values[$index]);
               }, Junctions::Or);
            }
         },
         'junction' => Junctions::And,
      ]];

      return $this;
   }

   /**
    * Skip a number of selected rows.
    */
   public function skip (int $offset): static
   {
      $this->offset = $offset;

      return $this;
   }

   /**
    * Normalize one mapped property, enum or identifier to a SQL identifier.
    */
   private function identify (BackedEnum|Stringable $Column): Identifier
   {
      if ($Column instanceof BackedEnum) {
         $value = $Column->value;

         if (is_string($value) === false) {
            throw new InvalidArgumentException('ORM selection enum identifiers must be string-backed.');
         }

         return new Identifier($this->Model->identify($value));
      }

      return new Identifier($this->Model->identify((string) $Column));
   }

   /**
    * Replay restriction predicates into one SQL builder.
    */
   private function restrict (Builder $Builder): void
   {
      // ?: Nothing of the repository's own — the caller's predicates as written.
      if ($this->guards === []) {
         $this->replay($Builder, $this->predicates);

         return;
      }

      // @ The caller's predicates as one group, then every guard group ANDed.
      if ($this->predicates !== []) {
         $this->join($Builder, $this->predicates);
      }

      foreach ($this->guards as $predicates) {
         $this->join($Builder, $predicates);
      }
   }

   /**
    * AND one group of predicates onto a SQL builder: nested when it holds
    * more than one, as is when it holds one — a lone predicate needs no
    * parentheses, and a nested one already has its own.
    *
    * @param array<int,array{column:BackedEnum|Stringable,operator:Operators,value:mixed,junction:Junctions}|array{column:BackedEnum|Stringable,value:mixed,match:Matches,junction:Junctions}|array{nest:Closure,junction:Junctions}> $predicates
    */
   private function join (Builder $Builder, array $predicates): void
   {
      // ?: One predicate — its own junction joined it to a neighbour that is
      //   no longer beside it.
      if (count($predicates) === 1) {
         $Predicate = $predicates[array_key_first($predicates)];
         $Predicate['junction'] = Junctions::And;

         $this->replay($Builder, [$Predicate]);

         return;
      }

      $Builder->nest(function (Builder $Group) use ($predicates): void {
         $this->replay($Group, $predicates);
      });
   }

   /**
    * Replay a list of predicates into one SQL builder, in call order.
    *
    * @param array<int,array{column:BackedEnum|Stringable,operator:Operators,value:mixed,junction:Junctions}|array{column:BackedEnum|Stringable,value:mixed,match:Matches,junction:Junctions}|array{nest:Closure,junction:Junctions}> $predicates
    */
   private function replay (Builder $Builder, array $predicates): void
   {
      // @@
      foreach ($predicates as $Predicate) {
         // ?: Grouped predicate scope.
         if (isSet($Predicate['nest'])) {
            $Builder->nest($Predicate['nest'], $Predicate['junction']);

            continue;
         }

         // ?: Text match.
         if (isSet($Predicate['match'])) {
            $Builder->match(
               $this->identify($Predicate['column']),
               $Predicate['value'],
               $Predicate['match'],
               $Predicate['junction']
            );

            continue;
         }

         $Builder->filter(
            $this->identify($Predicate['column']),
            $Predicate['operator'],
            $Predicate['value'],
            $Predicate['junction']
         );
      }
   }
}
