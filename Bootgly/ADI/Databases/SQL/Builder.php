<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use function array_keys;
use function count;
use function get_class;
use function implode;
use function is_string;
use function str_starts_with;
use function strlen;
use function substr;
use BackedEnum;
use Closure;
use InvalidArgumentException;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder\Aliasable;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Aggregates;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Capabilities;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Joins;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Junctions;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Locks;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Matches;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Modes;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Nulls;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Dialect;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Builder\Predicateable;
use Bootgly\ADI\Databases\SQL\Builder\Query;


/**
 * SQL query builder with Bootgly-compliant fluent verbs.
 *
 * @phpstan-type Predicate array{column:string,operator:Operators|Matches,value:mixed,junction:Junctions}
 * @phpstan-type PredicateGroup array{filters:array<int,array<string,mixed>>,junction:Junctions}
 */
class Builder
{
   use Aliasable;
   use Predicateable;

   // * Config
   public private(set) Dialect $Dialect;

   // * Data
   public private(set) Modes $Mode = Modes::Select;
   public private(set) null|string $table = null;
   public private(set) null|string $sourceAlias = null;
   public private(set) bool $distinct = false;
   /** @var array<int,string> */
   public private(set) array $columns = [];
   /** @var array<int,array{table:string,left:string,operator:Operators,right:string,join:Joins}> */
   public private(set) array $joins = [];
   /** @var array<int,string> */
   public private(set) array $groups = [];
   /** @var array<int,array{column:string,order:Orders,nulls:null|Nulls}> */
   public private(set) array $orders = [];
   public private(set) null|int $limited = null;
   public private(set) int $offset = 0;
   public private(set) null|Locks $Lock = null;

   // * Metadata
   /** @var array<int,array{name:string,query:self|Query,recursive:bool}> */
   private array $ctes = [];
   /** @var array<string,string> */
   private array $tableAliases = [];
   /** @var array<string,string> */
   private array $columnAliases = [];
   /** @var array<string,string> */
   private array $expressionAliases = [];
   private null|self|Query $source = null;
   /** @var array<string,array<int,mixed>> */
   private array $assignments = [];
   /** @var array<int,Predicate|PredicateGroup> */
   private array $filters = [];
   /** @var array<int,Predicate|PredicateGroup> */
   private array $havings = [];
   /** @var array<int,string> */
   private array $outputs = [];
   /** @var array<int,string> */
   private array $conflicts = [];
   /** @var array<int,array{method:string,arguments:array<int|string,mixed>}> */
   private array $actions = [];
   private bool $recording = true;
   private null|Query $Query = null;
   /** @var array<class-string<Dialect>,Query> */
   private array $Queries = [];


   /**
    * Create a SQL builder with one dialect strategy.
    */
   public function __construct (Dialect $Dialect = new PostgreSQL)
   {
      // * Config
      $this->Dialect = $Dialect;
   }

   /**
    * Record one fluent action for compile-time dialect replay.
    */
   private function record (string $method, mixed ...$arguments): void
   {
      $this->Query = null;
      $this->Queries = [];

      if ($this->recording === false) {
         return;
      }

      $this->actions[] = [
         'method' => $method,
         'arguments' => $arguments,
      ];
   }

   /**
    * Replay fluent actions into a builder with one dialect.
    *
   * @param array<int,array{method:string,arguments:array<int|string,mixed>}> $actions
    */
   private static function replay (array $actions, Dialect $Dialect): self
   {
      $Builder = new self($Dialect);
      $Builder->recording = false;

      foreach ($actions as $action) {
         $method = $action['method'];
         $Builder->$method(...$action['arguments']);
      }

      $Builder->recording = true;

      return $Builder;
   }

   /**
    * Select the SQL table for this query.
    */
   public function table (BackedEnum|Stringable|self|Query $Table, null|BackedEnum|Stringable $Alias = null): static
   {
      if ($Table instanceof self || $Table instanceof Query) {
         if ($Alias === null) {
            throw new InvalidArgumentException('SQL derived table requires an alias.');
         }

         $this->table = null;
         $this->source = $Table;
         $this->sourceAlias = $this->identify($Alias);
         $this->record(__FUNCTION__, $Table, $Alias);

         return $this;
      }

      $this->table = $this->identify($Table);
      $this->source = null;
      $this->sourceAlias = null;

      if ($Alias !== null) {
         $this->tableAliases[$this->table] = $this->identify($Alias);
         unset($this->columnAliases[$this->table]);
      }
      else {
         $this->promote($this->table);
      }

      $this->record(__FUNCTION__, $Table, $Alias);

      return $this;
   }

   /**
    * Define one common table expression.
    */
   public function define (BackedEnum|Stringable $Name, self|Query $Query, bool $recursive = false): static
   {
      $this->ctes[] = [
         'name' => $this->identify($Name),
         'query' => $Query,
         'recursive' => $recursive,
      ];
      $this->record(__FUNCTION__, $Name, $Query, $recursive);

      return $this;
   }

   /**
    * Add selected query columns.
    */
   public function select (BackedEnum|Stringable ...$Columns): static
   {
      $this->Mode = Modes::Select;

      foreach ($this->quote($Columns) as $column) {
         $this->columns[] = $column;
      }

      $this->record(__FUNCTION__, ...$Columns);

      return $this;
   }

   /**
    * Switch this builder to INSERT mode.
    */
   public function insert (): static
   {
      $this->Mode = Modes::Insert;

      if ($this->assignments !== []) {
         $this->measure();
      }

      $this->record(__FUNCTION__);

      return $this;
   }

   /**
    * Switch this builder to UPDATE mode.
    */
   public function update (): static
   {
      $this->Mode = Modes::Update;

      foreach ($this->assignments as $Values) {
         if (count($Values) !== 1) {
            throw new InvalidArgumentException('SQL update set values must be singular.');
         }
      }

      $this->record(__FUNCTION__);

      return $this;
   }

   /**
    * Switch this builder to DELETE mode.
    */
   public function delete (): static
   {
      $this->Mode = Modes::Delete;
      $this->record(__FUNCTION__);

      return $this;
   }

   /**
    * Select only distinct rows.
    */
   public function distinct (): static
   {
      $this->Mode = Modes::Select;
      $this->distinct = true;
      $this->record(__FUNCTION__);

      return $this;
   }

   /**
    * Assign one column value for INSERT or UPDATE.
    */
   public function set (BackedEnum|Stringable $Column, mixed $value, mixed ...$values): static
   {
      $Values = array_values([$value, ...$values]);

      if ($this->Mode === Modes::Update && count($Values) !== 1) {
         throw new InvalidArgumentException('SQL update set values must be singular.');
      }

      $this->assignments[$this->identify($Column)] = $Values;

      if ($this->Mode === Modes::Insert) {
         $this->measure();
      }

      $this->record(__FUNCTION__, $Column, $value, ...$values);

      return $this;
   }

   /**
    * Alias one table, column or expression.
    */
   public function alias (BackedEnum|Stringable $Identifier, BackedEnum|Stringable $Alias): static
   {
      $identifier = $this->identify($Identifier);
      $alias = $this->identify($Alias);

      if ($Identifier instanceof Expression) {
         if (isset($this->columnAliases[$identifier])) {
            throw new InvalidArgumentException('SQL expression alias conflicts with an existing column alias.');
         }

         $this->expressionAliases[$identifier] = $alias;
         $this->record(__FUNCTION__, $Identifier, $Alias);

         return $this;
      }

      if ($identifier === $this->table) {
         $this->tableAliases[$identifier] = $alias;
         $this->record(__FUNCTION__, $Identifier, $Alias);

         return $this;
      }

      foreach ($this->joins as $Join) {
         if ($Join['table'] === $identifier) {
            $this->tableAliases[$identifier] = $alias;
            $this->record(__FUNCTION__, $Identifier, $Alias);

            return $this;
         }
      }

      if (isset($this->expressionAliases[$identifier])) {
         throw new InvalidArgumentException('SQL column alias conflicts with an existing expression alias.');
      }

      $this->columnAliases[$identifier] = $alias;
      $this->record(__FUNCTION__, $Identifier, $Alias);

      return $this;
   }

   /**
    * Add a selected aggregate expression.
    */
   public function aggregate (Aggregates $Aggregate, BackedEnum|Stringable $Column, null|BackedEnum|Stringable $Alias = null): static
   {
      $column = $this->identify($Column);
      $expression = "{$Aggregate->value}({$column})";

      if ($Alias !== null) {
         $alias = $this->identify($Alias);
         $expression = "{$expression} AS {$alias}";
      }

      $this->Mode = Modes::Select;
      $this->columns[] = $expression;
      $this->record(__FUNCTION__, $Aggregate, $Column, $Alias);

      return $this;
   }

   /**
    * Count selected rows.
    */
   public function count (null|BackedEnum|Stringable $Alias = null): static
   {
      $expression = 'COUNT(*)';

      if ($Alias !== null) {
         $alias = $this->identify($Alias);
         $expression = "{$expression} AS {$alias}";
      }

      $this->Mode = Modes::Select;
      $this->columns[] = $expression;
      $this->record(__FUNCTION__, $Alias);

      return $this;
   }

   /**
    * Filter rows with one parameterized SQL predicate.
    */
   public function filter (BackedEnum|Stringable $Column, Operators $Operator, mixed $value = null, Junctions $Junction = Junctions::And): static
   {
      $this->validate($Operator, $value);

      $this->filters[] = [
         'column' => $this->identify($Column),
         'operator' => $Operator,
         'value' => $value,
         'junction' => $Junction,
      ];
      $this->record(__FUNCTION__, $Column, $Operator, $value, $Junction);

      return $this;
   }

   /**
    * Nest one grouped filter scope.
      *
      * The closure must be replayable for compile-time dialect selection.
    */
   public function nest (Closure $Group, Junctions $Junction = Junctions::And): static
   {
      $this->filters[] = $this->scope($Group, $Junction);
      $this->record(__FUNCTION__, $Group, $Junction);

      return $this;
   }

   /**
    * Match text with LIKE, ILIKE or PostgreSQL full-text predicates.
    */
   public function match (BackedEnum|Stringable $Column, mixed $value, Matches $Match = Matches::Like, Junctions $Junction = Junctions::And): static
   {
      if (is_string($value) === false) {
         throw new InvalidArgumentException('SQL text match value must be a string.');
      }

      $this->filters[] = [
         'column' => $this->identify($Column),
         'operator' => $Match,
         'value' => $value,
         'junction' => $Junction,
      ];
      $this->record(__FUNCTION__, $Column, $value, $Match, $Junction);

      return $this;
   }

   /**
    * Filter grouped rows with a parameterized SQL predicate.
    */
   public function having (BackedEnum|Stringable $Column, Operators $Operator, mixed $value = null, Junctions $Junction = Junctions::And): static
   {
      $this->validate($Operator, $value);

      $this->havings[] = [
         'column' => $this->identify($Column),
         'operator' => $Operator,
         'value' => $value,
         'junction' => $Junction,
      ];
      $this->record(__FUNCTION__, $Column, $Operator, $value, $Junction);

      return $this;
   }

   /**
    * Join another table through an identifier comparison.
    */
   public function join (BackedEnum|Stringable $Table, BackedEnum|Stringable $Left, Operators $Operator, BackedEnum|Stringable $Right, Joins $Join = Joins::Inner): static
   {
      $table = $this->identify($Table);

      $this->joins[] = [
         'table' => $table,
         'left' => $this->identify($Left),
         'operator' => $Operator,
         'right' => $this->identify($Right),
         'join' => $Join,
      ];
      $this->promote($table);
      $this->record(__FUNCTION__, $Table, $Left, $Operator, $Right, $Join);

      return $this;
   }

   /**
    * Group selected rows by one or more columns.
    */
   public function group (BackedEnum|Stringable ...$Columns): static
   {
      foreach ($this->quote($Columns) as $column) {
         $this->groups[] = $column;
      }

      $this->record(__FUNCTION__, ...$Columns);

      return $this;
   }

   /**
    * Order selected rows by one column.
    */
   public function order (Orders $Order, BackedEnum|Stringable $Column, null|Nulls $Nulls = null): static
   {
      if ($Column instanceof Nulls) {
         throw new InvalidArgumentException('SQL order requires a column identifier.');
      }

      $this->orders[] = [
         'column' => $this->identify($Column),
         'order' => $Order,
         'nulls' => $Nulls,
      ];

      $this->record(__FUNCTION__, $Order, $Column, $Nulls);

      return $this;
   }

   /**
    * Limit selected row count and optional offset.
    */
   public function limit (int $count, int $offset = 0): static
   {
      if ($count < 0 || $offset < 0) {
         throw new InvalidArgumentException('SQL limit and offset must be non-negative integers.');
      }

      $this->limited = $count;
      $this->offset = $offset;
      $this->record(__FUNCTION__, $count, $offset);

      return $this;
   }

   /**
    * Output mutation rows through RETURNING.
    */
   public function output (BackedEnum|Stringable ...$Columns): static
   {
      if ($this->Dialect->check(Capabilities::Output) === false) {
         throw new InvalidArgumentException('SQL dialect does not support RETURNING output.');
      }

      foreach ($this->quote($Columns) as $column) {
         $this->outputs[] = $column;
      }

      $this->record(__FUNCTION__, ...$Columns);

      return $this;
   }

   /**
    * Apply PostgreSQL ON CONFLICT upsert handling.
    */
   public function upsert (BackedEnum|Stringable ...$Columns): static
   {
      if ($this->Dialect->check(Capabilities::Upsert) === false) {
         throw new InvalidArgumentException('SQL dialect does not support upsert handling.');
      }

      if ($Columns === []) {
         throw new InvalidArgumentException('SQL upsert requires at least one conflict column.');
      }

      $this->Mode = Modes::Insert;
      $this->conflicts = $this->quote($Columns);
      $this->record(__FUNCTION__, ...$Columns);

      return $this;
   }

   /**
    * Lock selected rows.
    */
   public function lock (Locks $Lock): static
   {
      $this->Lock = $Lock;
      $this->record(__FUNCTION__, $Lock);

      return $this;
   }

   /**
    * Skip a number of selected rows.
    */
   public function skip (int $offset): static
   {
      if ($offset < 0) {
         throw new InvalidArgumentException('SQL offset must be a non-negative integer.');
      }

      $this->offset = $offset;
      $this->record(__FUNCTION__, $offset);

      return $this;
   }

   /**
    * Compile this builder into SQL and ordered parameters.
    */
   public function compile (null|Dialect $Dialect = null): Query
   {
      if ($Dialect !== null) {
         $class = get_class($Dialect);

         if ($class !== get_class($this->Dialect)) {
            $this->Queries[$class] ??= self::replay($this->actions, $Dialect)->compile();

            return $this->Queries[$class];
         }
      }

      if ($this->Query !== null) {
         return $this->Query;
      }

      $parameters = [];
      $this->guard();
      $prefix = $this->prepend($parameters);

      $sql = match ($this->Mode) {
         Modes::Delete => $this->remove($parameters),
         Modes::Insert => $this->create($parameters),
         Modes::Select => $this->project($parameters),
         Modes::Update => $this->change($parameters),
      };

      $this->Query = new Query("{$prefix}{$sql}", $parameters);

      return $this->Query;
   }

   /**
    * Compile SELECT SQL.
    *
    * @param array<int,mixed> $parameters
    */
   private function project (array &$parameters): string
   {
      $table = $this->derive($parameters);
      $columns = $this->columns === [] ? '*' : implode(', ', $this->map($this->columns));
      $select = $this->distinct ? 'SELECT DISTINCT' : 'SELECT';
      $sql = "{$select} {$columns} FROM {$table}";

      foreach ($this->joins as $Join) {
         $table = $this->render($Join['table'], table: true);
         $left = $this->target($Join['left'], exact: false);
         $right = $this->target($Join['right'], exact: false);
         $sql = "{$sql} {$Join['join']->value} {$table} ON {$left} {$Join['operator']->value} {$right}";
      }

      $filters = $this->combine($this->filters, $parameters);

      if ($filters !== '') {
         $sql = "{$sql} WHERE {$filters}";
      }

      if ($this->groups !== []) {
         $groups = implode(', ', $this->refer($this->groups));
         $sql = "{$sql} GROUP BY {$groups}";
      }

      $havings = $this->combine($this->havings, $parameters, exact: true);

      if ($havings !== '') {
         $sql = "{$sql} HAVING {$havings}";
      }

      if ($this->orders !== []) {
         $orders = [];

         foreach ($this->orders as $Order) {
            $column = $this->target($Order['column']);
            $orders[] = $this->Dialect->order($column, $Order['order'], $Order['nulls']);
         }

         $ordered = implode(', ', $orders);
         $sql = "{$sql} ORDER BY {$ordered}";
      }

      if ($this->limited !== null) {
         $sql = "{$sql} LIMIT {$this->limited}";
      }

      if ($this->offset > 0) {
         $sql = "{$sql} OFFSET {$this->offset}";
      }

      if ($this->Lock !== null) {
         $sql = "{$sql} {$this->Lock->value}";
      }

      return $sql;
   }

   /**
    * Compile INSERT SQL.
    *
    * @param array<int,mixed> $parameters
    */
   private function create (array &$parameters): string
   {
      $columns = implode(', ', array_keys($this->assignments));
      $rows = [];
      $count = $this->measure();

      for ($row = 0; $row < $count; $row++) {
         $values = [];

         foreach (array_values($this->assignments) as $Values) {
            $values[] = $this->bind($parameters, $Values[$row]);
         }

         $placeholders = implode(', ', $values);
         $rows[] = "({$placeholders})";
      }

      $values = implode(', ', $rows);
      $table = $this->table ?? '';
      $conflict = $this->resolve();
      $sql = "INSERT INTO {$this->render($table, table: true)} ({$columns}) VALUES {$values}{$conflict}";

      return $this->emit($sql);
   }

   /**
    * Compile UPDATE SQL.
    *
    * @param array<int,mixed> $parameters
    */
   private function change (array &$parameters): string
   {
      $sets = [];

      foreach ($this->assignments as $column => $Values) {
         if (count($Values) !== 1) {
            throw new InvalidArgumentException('SQL update set values must be singular.');
         }

         $value = $Values[0];
         $placeholder = $this->bind($parameters, $value);
         $sets[] = "{$column} = {$placeholder}";
      }

      $assignments = implode(', ', $sets);
      $filters = $this->combine($this->filters, $parameters);

      $table = $this->table ?? '';
      $sql = "UPDATE {$this->render($table, table: true)} SET {$assignments} WHERE {$filters}";

      return $this->emit($sql);
   }

   /**
    * Compile DELETE SQL.
    *
    * @param array<int,mixed> $parameters
    */
   private function remove (array &$parameters): string
   {
      $filters = $this->combine($this->filters, $parameters);

      $table = $this->table ?? '';
      $sql = "DELETE FROM {$this->render($table, table: true)} WHERE {$filters}";

      return $this->emit($sql);
   }

   /**
    * Validate the current builder state.
    */
   private function guard (): void
   {
      if ($this->table === null && $this->source === null) {
         throw new InvalidArgumentException('SQL builder requires a table.');
      }

      if ($this->source !== null && $this->Mode !== Modes::Select) {
         throw new InvalidArgumentException('SQL derived tables are only valid for SELECT.');
      }

      if (($this->Mode === Modes::Insert || $this->Mode === Modes::Update) && $this->assignments === []) {
         throw new InvalidArgumentException('SQL insert/update requires at least one set value.');
      }

      if (($this->Mode === Modes::Update || $this->Mode === Modes::Delete) && $this->filters === []) {
         throw new InvalidArgumentException('SQL update/delete requires at least one filter.');
      }
   }

   /**
    * Bind one parameter value and return its positional placeholder.
    *
    * @param array<int,mixed> $parameters
    */
   private function bind (array &$parameters, mixed $value): string
   {
      if ($value instanceof Expression) {
         return $value->sql;
      }

      $parameters[] = $value;
      $position = count($parameters);

      return $this->Dialect->mark($position);
   }

   /**
    * Render one aliased identifier reference when present.
    */
   private function target (string $identifier, bool $exact = true): string
   {
      if ($exact && isset($this->columnAliases[$identifier])) {
         return $this->columnAliases[$identifier];
      }

      if ($exact && isset($this->expressionAliases[$identifier])) {
         return $this->expressionAliases[$identifier];
      }

      foreach ($this->tableAliases as $source => $alias) {
         $prefix = "{$source}.";

         if (str_starts_with($identifier, $prefix)) {
            $suffix = substr($identifier, strlen($prefix));

            return "{$alias}.{$suffix}";
         }
      }

      return $identifier;
   }

   /**
    * Spawn one nested builder with the current dialect.
    */
   private function spawn (): self
   {
      return new self($this->Dialect);
   }

   /**
    * Measure and validate INSERT row count.
    */
   private function measure (): int
   {
      $count = null;

      foreach ($this->assignments as $Values) {
         $size = count($Values);

         if ($count === null) {
            $count = $size;

            continue;
         }

         if ($count !== $size) {
            throw new InvalidArgumentException('SQL insert set columns must have the same value count.');
         }
      }

      return $count ?? 0;
   }

   /**
    * Compile PostgreSQL ON CONFLICT handling when configured.
    */
   private function resolve (): string
   {
      return $this->Dialect->upsert($this->assignments, $this->conflicts);
   }

   /**
    * Embed and merge one subquery into the current parameter list.
    *
    * @param array<int,mixed> $parameters
    */
   private function embed (self|Query $query, array &$parameters): string
   {
      $Query = $query instanceof self ? $query->compile($this->Dialect) : $query;
      $offset = count($parameters);
      $sql = $this->Dialect->rebase($Query->sql, $offset);

      foreach ($Query->parameters as $parameter) {
         $parameters[] = $parameter;
      }

      return $sql;
   }

   /**
    * Compile common table expressions before the main statement.
    *
    * @param array<int,mixed> $parameters
    */
   private function prepend (array &$parameters): string
   {
      if ($this->ctes === []) {
         return '';
      }

      $ctes = [];
      $recursive = false;

      foreach ($this->ctes as $Cte) {
         $query = $this->embed($Cte['query'], $parameters);
         $ctes[] = "{$Cte['name']} AS ({$query})";
         $recursive = $recursive || $Cte['recursive'];
      }

      $prefix = implode(', ', $ctes);
      $with = $recursive ? 'WITH RECURSIVE' : 'WITH';

      return "{$with} {$prefix} ";
   }

   /**
    * Compile the SELECT source table or derived subquery.
    *
    * @param array<int,mixed> $parameters
    */
   private function derive (array &$parameters): string
   {
      if ($this->source === null) {
         $table = $this->table ?? '';

         return $this->render($table, table: true);
      }

      $query = $this->embed($this->source, $parameters);
      $alias = $this->sourceAlias ?? '';

      return "({$query}) AS {$alias}";
   }

   /**
    * Append RETURNING output columns when configured.
    */
   private function emit (string $sql): string
   {
      if ($this->outputs === []) {
         return $sql;
      }

      return $this->Dialect->output($sql, $this->map($this->outputs));
   }
}
