<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


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
   /** @var array<int,array{column:BackedEnum|Stringable,operator:Operators,value:mixed,junction:Junctions}|array{nest:Closure,junction:Junctions}> */
   private array $filters = [];
   /** @var array<int,array{column:BackedEnum|Stringable,value:mixed,match:Matches,junction:Junctions}> */
   private array $matches = [];
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
      $this->filters[] = [
         'column' => $Column,
         'operator' => $Operator,
         'value' => $value,
         'junction' => $Junction,
      ];

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
      $this->matches[] = [
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
      $this->filters[] = [
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
      ];

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
      foreach ($this->filters as $Filter) {
         // ?: Grouped predicate scope.
         if (isSet($Filter['nest'])) {
            $Builder->nest($Filter['nest'], $Filter['junction']);

            continue;
         }

         $Builder->filter(
            $this->identify($Filter['column']),
            $Filter['operator'],
            $Filter['value'],
            $Filter['junction']
         );
      }

      foreach ($this->matches as $Match) {
         $Builder->match(
            $this->identify($Match['column']),
            $Match['value'],
            $Match['match'],
            $Match['junction']
         );
      }
   }
}
