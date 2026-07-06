<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use function array_key_exists;
use function array_values;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function serialize;
use Closure;
use InvalidArgumentException;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use WeakMap;

use Bootgly\ADI\Database\Operation\Result as DatabaseResult;
use Bootgly\ADI\Databases\SQL\Awaiting;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Capabilities;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Dialect;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Model\Auxiliaries\Relations;
use Bootgly\ADI\Databases\SQL\Model\Relation;
use Bootgly\ADI\Databases\SQL\Models;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Querying;
use Bootgly\ADI\Databases\SQL\Repository\Hooks;
use Bootgly\ADI\Databases\SQL\Repository\Hydrator;
use Bootgly\ADI\Databases\SQL\Repository\Identity;
use Bootgly\ADI\Databases\SQL\Repository\LazyBatch;
use Bootgly\ADI\Databases\SQL\Repository\LazyCollection;
use Bootgly\ADI\Databases\SQL\Repository\LazyReference;
use Bootgly\ADI\Databases\SQL\Repository\Result as MappedResult;
use Bootgly\ADI\Databases\SQL\Repository\Selection;


/**
 * ORM Data Mapper repository for one mapped entity class.
 */
class Repository
{
   // * Config
   public private(set) Querying $Querying;
   public private(set) Dialect $Dialect;
   public private(set) Model $Model;
   public private(set) Models $Models;
   public private(set) null|Awaiting $Awaiting;

   // * Data
   public private(set) Identity $Identity;
   private null|object $Scope;
   /** @var WeakMap<Operation,array{loads:array<int,string>,lazies:array<int,string>,Scope:null|object}> */
   private WeakMap $contexts;
   /** @var array<string,Closure> */
   private array $scopes = [];
   /** @var array<string,array<int,Closure>> */
   private array $listeners = [];

   // * Metadata
   private const string PIVOT_LOCAL = '__orm_local';
   private Hydrator $Hydrator;


   public function __construct (Querying $Querying, Dialect $Dialect, Model $Model, Models $Models, null|object $Scope = null, null|Awaiting $Awaiting = null)
   {
      // * Config
      $this->Querying = $Querying;
      $this->Dialect = $Dialect;
      $this->Model = $Model;
      $this->Models = $Models;
      $this->Awaiting = $Awaiting;

      // * Data
      $this->Identity = new Identity;
      $this->Scope = $Scope;
      $this->contexts = new WeakMap;

      // * Metadata
      $this->Hydrator = new Hydrator($Model, $this->Identity);
   }

   /**
    * Create one ORM repository context.
    *
    * @param class-string $Entity
    */
   public static function create (Querying $Querying, Dialect $Dialect, Models $Models, string $Entity, null|object $Scope = null, null|Awaiting $Awaiting = null): self
   {
      return new self(
         $Querying,
         $Dialect,
         $Models->fetch($Entity),
         $Models,
         $Scope,
         $Awaiting
      );
   }

   /**
    * Materialize one loaded relation and attach related entities.
    *
    * @param object|array<int,object> $Entities
    */
   public function attach (object|array $Entities, string $relation, Operation|DatabaseResult $Source): MappedResult
   {
      // ! Parent entities and relation.
      $Entities = is_array($Entities) ? array_values($Entities) : [$Entities];
      $Relation = $this->Model->relations[$relation] ?? null;

      // ? Relation mapping.
      if ($Relation === null) {
         throw new InvalidArgumentException("ORM relation is not mapped: {$relation}");
      }

      // ? Parent model validation.
      foreach ($Entities as $Entity) {
         $this->Model->validate($Entity);
      }

      // @ Target hydration.
      [$Result, $Target, $entities] = $this->materialize($Relation, $Source);
      $groups = $this->group($Relation, $Target, $Result, $entities);

      // @ Relation assignment.
      $this->assign($Entities, $relation, $Relation, $groups);

      // : Mapped relation result.
      return new MappedResult($Result, $entities);
   }

   /**
    * Delete one entity or one entity key.
    */
   public function delete (mixed $Entity, null|object $Scope = null): Operation
   {
      $id = $Entity;

      if (is_object($Entity)) {
         $this->Model->validate($Entity);
         $id = $this->Model->read($Entity, $this->Model->keyProperty);
      }

      $this->emit(Hooks::Deleting, $Entity);

      $Builder = $this->build()->delete()->filter(new Identifier($this->Model->key), Operators::Equal, $id);
      $Operation = $this->Querying->query($Builder, Scope: $Scope ?? $this->Scope);

      $this->emit(Hooks::Deleted, $Operation, $Entity);

      return $Operation;
   }

   /**
    * Fetch entities through one ORM selection.
    */
   public function fetch (null|Selection $Selection = null, null|object $Scope = null): Operation
   {
      $Selection ??= $this->select();
      $Scope ??= $this->Scope;
      $this->apply($Selection);
      $this->emit(Hooks::Selecting, $Selection);

      $Operation = $this->Querying->query($Selection->compile(), Scope: $Scope);
      $loads = $Selection->loads;
      $lazies = $this->detect($loads);

      if ($loads !== [] || $lazies !== []) {
         $this->contexts[$Operation] = [
            'loads' => $loads,
            'lazies' => $lazies,
            'Scope' => $Scope,
         ];
      }

      $this->emit(Hooks::Selected, $Operation, $Selection);

      return $Operation;
   }

   /**
    * Find one entity by primary key.
    */
   public function find (mixed $id, null|object $Scope = null): Operation
   {
      $Selection = $this->select()
         ->filter(new Identifier($this->Model->key), Operators::Equal, $id)
         ->limit(1);

      return $this->fetch($Selection, $Scope);
   }

   /**
    * Hydrate one finished SQL operation or database result.
    *
    * If this repository has no await bridge, the returned mapped result exposes
    * pending relation operations in `loads`. If an await bridge is configured,
    * the relation operations are awaited and attached before this method returns.
    */
   public function hydrate (Operation|DatabaseResult $Source): MappedResult
   {
      // ? Completed source result.
      $Result = $Source instanceof Operation ? $Source->Result : $Source;

      if ($Source instanceof Operation && $Source->error !== null) {
         throw new RuntimeException($Source->error);
      }

      if ($Result === null) {
         throw new RuntimeException('ORM hydration requires a completed SQL result.');
      }

      // ! Pending relation context registered by the source selection.
      $loads = [];
      $lazies = $this->detect($loads);
      $Scope = $this->Scope;

      if ($Source instanceof Operation && isset($this->contexts[$Source])) {
         $context = $this->contexts[$Source];
         $loads = $context['loads'];
         $lazies = $context['lazies'];
         $Scope = $context['Scope'];
         unset($this->contexts[$Source]);
      }

      // ? Lazy loading bridge.
      if ($lazies !== [] && $this->Awaiting === null) {
         throw new RuntimeException('ORM lazy loading requires an await bridge.');
      }

      // @ Hydration and deferred/eager relation operations.
      $this->emit(Hooks::Hydrating, $Result);
      $entities = $this->Hydrator->hydrate($Result);

      if ($lazies !== []) {
         $this->install($entities, $lazies, $Scope);
      }

      $operations = $loads === [] || $this->Awaiting !== null ? [] : $this->load($entities, $loads, $Scope);
      $Mapped = new MappedResult($Result, $entities, $operations);

      if ($loads !== [] && $this->Awaiting !== null) {
         $this->pull($Mapped, $loads, $Scope);
      }

      $this->emit(Hooks::Hydrated, $Mapped);

      // : Mapped result with hydrated entities and relation state.
      return $Mapped;
   }

   /**
    * Register one local lifecycle listener.
    */
   public function listen (Hooks $Hook, Closure $Listener): static
   {
      $this->listeners[$Hook->name][] = $Listener;

      return $this;
   }

   /**
    * Load one or more single-level relations through explicit batch operations.
    *
    * The returned operations are keyed by relation name and are not awaited or
    * attached here. Await each operation explicitly and pass it to `attach()`.
    *
    * @param object|array<int,object> $Entities
    * @param array<int,string> $relations
    * @return array<string,Operation>
    */
   public function load (object|array $Entities, array $relations = [], null|object $Scope = null): array
   {
      // ! Parent entities.
      $Entities = is_array($Entities) ? array_values($Entities) : [$Entities];
      $operations = [];

      // ? Nothing to load.
      if ($Entities === []) {
         return $operations;
      }

      // ? Parent model validation.
      foreach ($Entities as $Entity) {
         $this->Model->validate($Entity);
      }

      // @ Relation operations.
      foreach ($relations as $name) {
         $Relation = $this->Model->relations[$name] ?? null;

         if ($Relation === null) {
            throw new InvalidArgumentException("ORM relation is not mapped: {$name}");
         }

         $Target = $this->Models->fetch($Relation->target);
         $keys = $this->collect($Entities, $Relation->local);

         if ($keys === []) {
            continue;
         }

         if ($Relation->Type === Relations::BelongsToMany) {
            $operations[$name] = $this->join($Target, $Relation, $keys, $Scope);

            continue;
         }

         $column = $Target->identify($Relation->foreign);
         $Builder = (new Builder($this->Dialect))
            ->table(new Identifier($Target->table));

         foreach ($Target->columns as $targetColumn => $_) {
            $Builder->select(new Identifier($targetColumn));
         }

         $Builder->filter(new Identifier($column), Operators::In, $keys);
         $operations[$name] = $this->Querying->query($Builder, Scope: $Scope ?? $this->Scope);
      }

      // : Operations by relation name.
      return $operations;
   }

   /**
    * Reset tracked ORM runtime identities.
    */
   public function reset (): static
   {
      $this->Identity->reset();

      return $this;
   }

   /**
    * Save one entity through INSERT or UPDATE.
    *
    * For non-generated primary keys the INSERT/UPDATE choice relies on this
    * repository's identity map: an untracked key is treated as an INSERT. Persist
    * such an entity through the same repository that hydrated it — saving it through
    * a fresh repository (empty identity map) would route to INSERT and raise a
    * duplicate-key error at the database.
    */
   public function save (object $Entity, null|object $Scope = null): Operation
   {
      // ? Entity model validation.
      $this->Model->validate($Entity);
      $this->emit(Hooks::Saving, $Entity);

      // @ INSERT/UPDATE decision.
      $id = $this->Model->read($Entity, $this->Model->keyProperty);
      $insert = $this->Model->generated
         ? $id === null
         : $id !== null && $this->Identity->fetch($this->Model->class, $id) === null;
      $Builder = $insert
         ? $this->insert($Entity)
         : $this->update($Entity, $id);
      $Operation = $this->Querying->query($Builder, Scope: $Scope ?? $this->Scope);

      $this->emit(Hooks::Saved, $Operation, $Entity);

      // : Persistence operation.
      return $Operation;
   }

   /**
    * Start one ORM selection for this repository.
    */
   public function select (): Selection
   {
      return new Selection($this->Model, $this->Dialect);
   }

   /**
    * Register one named query scope.
    */
   public function scope (string $name, Closure $Scope): static
   {
      $this->scopes[$name] = $Scope;

      return $this;
   }

   /**
    * Apply named scopes to one selection.
    */
   private function apply (Selection $Selection): void
   {
      foreach ($Selection->scopes as $name) {
         $Scope = $this->scopes[$name] ?? null;

         if ($Scope === null) {
            throw new InvalidArgumentException("ORM scope is not registered: {$name}");
         }

         $Scope($Selection, $this);
      }
   }

   /**
    * Assign relation entities onto their parent entities.
    *
    * @param array<int,object> $Entities
    * @param array<string,array<int,object>> $groups
    */
   private function assign (array $Entities, string $relation, Relation $Relation, array $groups): void
   {
      // ! Relation property and local key.
      $property = $this->Model->relationProperties[$relation];
      $local = isset($this->Model->properties[$Relation->local])
         ? $Relation->local
         : $this->Model->resolve($Relation->local);

      // @ Lazy wrapper assignment.
      if ($Relation->lazy) {
         $class = match ($Relation->Type) {
            Relations::BelongsTo, Relations::HasOne => LazyReference::class,
            Relations::BelongsToMany, Relations::HasMany => LazyCollection::class,
         };
         $this->accept($property, $class);
         $Batch = new LazyBatch(static fn (): array => $groups);
         $Batch->set($groups);

         foreach ($Entities as $Entity) {
            $key = $this->Model->read($Entity, $local);
            $index = $key === null ? null : $this->index($key);
            $value = match ($Relation->Type) {
               Relations::BelongsTo, Relations::HasOne => new LazyReference($Batch, $index),
               Relations::BelongsToMany, Relations::HasMany => new LazyCollection($Batch, $index),
            };

            $this->Model->write($Entity, $property, $value);
         }

         return;
      }

      // @ Write grouped relations onto parents by cardinality.
      foreach ($Entities as $Entity) {
         $key = $this->Model->read($Entity, $local);
         $index = $key === null ? null : $this->index($key);
         $related = $index === null ? [] : $groups[$index] ?? [];
         $value = match ($Relation->Type) {
            Relations::BelongsTo, Relations::HasOne => $related[0] ?? null,
            Relations::BelongsToMany, Relations::HasMany => $related,
         };

         $this->Model->write($Entity, $property, $value);
      }
   }

   /**
    * Validate whether one relation property accepts one lazy wrapper class.
    */
   private function accept (string $property, string $class): void
   {
      $Reflection = $this->Model->Reflections[$property] ?? null;

      if ($Reflection === null) {
         throw new InvalidArgumentException("ORM property is not mapped: {$property}");
      }

      $Type = $Reflection->getType();

      if ($Type === null || $this->allow($Type, $class)) {
         return;
      }

      throw new RuntimeException("ORM lazy relation property must accept {$class}: {$this->Model->class}::\${$property}");
   }

   /**
    * Check whether one reflection type accepts one lazy wrapper class.
    */
   private function allow (ReflectionType $Type, string $class): bool
   {
      if ($Type instanceof ReflectionNamedType) {
         $name = $Type->getName();

         if ($name === $class || $name === 'mixed' || $name === 'object') {
            return true;
         }

         return $class === LazyCollection::class && $name === 'iterable';
      }

      if ($Type instanceof ReflectionUnionType) {
         foreach ($Type->getTypes() as $UnionType) {
            if ($this->allow($UnionType, $class)) {
               return true;
            }
         }
      }

      return false;
   }

   /**
    * Build a SQL builder for the mapped table.
    */
   private function build (): Builder
   {
      return (new Builder($this->Dialect))->table(new Identifier($this->Model->table));
   }

   /**
    * Collect unique relation key values from entities.
    *
    * @param array<int,object> $Entities
    * @return array<int,mixed>
    */
   private function collect (array $Entities, string $name): array
   {
      $property = isset($this->Model->properties[$name])
         ? $name
         : $this->Model->resolve($name);
      $values = [];

      foreach ($Entities as $Entity) {
         $value = $this->Model->read($Entity, $property);

         if ($value === null) {
            continue;
         }

         $values[$this->index($value)] = $value;
      }

      return array_values($values);
   }

   /**
    * Select lazy relation names not explicitly materialized by the selection.
    *
    * @param array<int,string> $loads
    * @return array<int,string>
    */
   private function detect (array $loads): array
   {
      $lazies = [];

      foreach ($this->Model->relations as $name => $Relation) {
         if ($Relation->lazy === false || in_array($name, $loads, true)) {
            continue;
         }

         $lazies[] = $name;
      }

      return $lazies;
   }

   /**
    * Normalize relation batch keys for duplicate filtering.
    */
   private function index (mixed $key): string
   {
      if (is_bool($key)) {
         return $key ? 'true' : 'false';
      }

      if (is_float($key) || is_int($key) || is_string($key)) {
         return (string) $key;
      }

      return serialize($key);
   }

   /**
    * Emit one local lifecycle hook.
    */
   private function emit (Hooks $Hook, mixed ...$arguments): void
   {
      foreach ($this->listeners[$Hook->name] ?? [] as $Listener) {
         $Listener(...$arguments);
      }
   }

   /**
    * Await and attach pending eager relation operations.
    *
    * @param array<int,string> $loads
    */
   private function pull (MappedResult $Mapped, array $loads, null|object $Scope = null): void
   {
      if ($Mapped->entities === []) {
         return;
      }

      $Awaiting = $this->Awaiting;

      if ($Awaiting === null) {
         return;
      }

      foreach ($loads as $relation) {
         $Operations = $this->load($Mapped->entities, [$relation], $Scope);
         $Operation = $Operations[$relation] ?? null;

         if ($Operation === null) {
            continue;
         }

         $Operation = $Awaiting->await($Operation);
         $this->attach($Mapped->entities, $relation, $Operation);
      }
   }

   /**
    * Group target entities by parent local relation key.
    *
    * @param array<int,object> $Targets
    * @return array<string,array<int,object>>
    */
   private function group (Relation $Relation, Model $Target, DatabaseResult $Result, array $Targets): array
   {
      $groups = [];

      foreach ($Targets as $index => $TargetEntity) {
         if ($Relation->Type === Relations::BelongsToMany) {
            $key = $Result->rows[$index][self::PIVOT_LOCAL] ?? null;
         }
         else {
            $foreign = isset($Target->properties[$Relation->foreign])
               ? $Relation->foreign
               : $Target->resolve($Relation->foreign);
            $key = $Target->read($TargetEntity, $foreign);
         }

         if ($key === null) {
            continue;
         }

         $groups[$this->index($key)][] = $TargetEntity;
      }

      return $groups;
   }

   /**
    * Install lazy relation wrappers on hydrated parent entities.
    *
    * @param array<int,object> $Entities
    * @param array<int,string> $relations
    */
   private function install (array $Entities, array $relations, null|object $Scope = null): void
   {
      if ($Entities === []) {
         return;
      }

      foreach ($relations as $relation) {
         $Relation = $this->Model->relations[$relation] ?? null;

         if ($Relation === null) {
            throw new InvalidArgumentException("ORM relation is not mapped: {$relation}");
         }

         $property = $this->Model->relationProperties[$relation];
         $class = match ($Relation->Type) {
            Relations::BelongsTo, Relations::HasOne => LazyReference::class,
            Relations::BelongsToMany, Relations::HasMany => LazyCollection::class,
         };
         $this->accept($property, $class);
         $local = isset($this->Model->properties[$Relation->local])
            ? $Relation->local
            : $this->Model->resolve($Relation->local);
         $Batch = new LazyBatch(function () use ($Entities, $relation, $Relation, $Scope): array {
            $Awaiting = $this->Awaiting;

            if ($Awaiting === null) {
               throw new RuntimeException('ORM lazy loading requires an await bridge.');
            }

            $Operations = $this->load($Entities, [$relation], $Scope);
            $Operation = $Operations[$relation] ?? null;

            if ($Operation === null) {
               return [];
            }

            $Operation = $Awaiting->await($Operation);
            [$Result, $Target, $targets] = $this->materialize($Relation, $Operation);

            return $this->group($Relation, $Target, $Result, $targets);
         });

         foreach ($Entities as $Entity) {
            $key = $this->Model->read($Entity, $local);
            $index = $key === null ? null : $this->index($key);
            $value = match ($Relation->Type) {
               Relations::BelongsTo, Relations::HasOne => new LazyReference($Batch, $index),
               Relations::BelongsToMany, Relations::HasMany => new LazyCollection($Batch, $index),
            };

            $this->Model->write($Entity, $property, $value);
         }
      }
   }

   /**
    * Build an INSERT query for one entity.
    */
   private function insert (object $Entity): Builder
   {
      if ($this->Model->insertions === []) {
         throw new RuntimeException('ORM insert requires at least one writable column.');
      }

      $Builder = $this->build()->insert();

      foreach ($this->Model->insertions as $column => $property) {
         $value = $this->Model->read($Entity, $property);

         if ($value === null && $this->Model->definitions[$column]->generated) {
            continue;
         }

         $Builder->set(new Identifier($column), $value);
      }

      $this->output($Builder);

      return $Builder;
   }

   /**
    * Add mutation output columns where supported.
    */
   private function output (Builder $Builder): void
   {
      if ($this->Dialect->check(Capabilities::Output) === false) {
         return;
      }

      foreach ($this->Model->columns as $column => $_) {
         $Builder->output(new Identifier($column));
      }
   }

   /**
    * Join a many-to-many relation query through a pivot table.
    *
    * @param array<int,mixed> $keys
    */
   private function join (Model $Target, Relation $Relation, array $keys, null|object $Scope = null): Operation
   {
      if ($Relation->table === null || $Relation->pivotLocal === null || $Relation->pivotForeign === null) {
         throw new InvalidArgumentException('ORM belongsToMany relation requires pivot table and keys.');
      }

      $target = $Target->table;
      $pivot = $Relation->table;
      $foreign = $Target->identify($Relation->foreign);
      $Builder = (new Builder($this->Dialect))
         ->table(new Identifier($target))
         ->join(
            new Identifier($pivot),
            new Identifier("{$target}.{$foreign}"),
            Operators::Equal,
            new Identifier("{$pivot}.{$Relation->pivotForeign}")
         )
         ->filter(new Identifier("{$pivot}.{$Relation->pivotLocal}"), Operators::In, $keys);

      foreach ($Target->columns as $column => $_) {
         $Builder->select(new Identifier("{$target}.{$column}"));
      }

      $Builder
         ->select(new Identifier("{$pivot}.{$Relation->pivotLocal}"))
         ->alias(new Identifier("{$pivot}.{$Relation->pivotLocal}"), new Identifier(self::PIVOT_LOCAL));

      return $this->Querying->query($Builder, Scope: $Scope ?? $this->Scope);
   }

   /**
    * Materialize one relation source into target entities.
    *
    * @return array{0:DatabaseResult,1:Model,2:array<int,object>}
    */
   private function materialize (Relation $Relation, Operation|DatabaseResult $Source): array
   {
      // ? Completed source result.
      $Result = $Source instanceof Operation ? $Source->Result : $Source;

      if ($Source instanceof Operation && $Source->error !== null) {
         throw new RuntimeException($Source->error);
      }

      if ($Result === null) {
         throw new RuntimeException('ORM relation attachment requires a completed SQL result.');
      }

      // @ Target hydration.
      $Target = $this->Models->fetch($Relation->target);
      $Hydrator = new Hydrator($Target, $this->Identity);
      $entities = $Hydrator->hydrate($Result);

      // ? Relation key column presence (reject shape mismatch instead of empty result).
      if ($entities !== []) {
         $expected = $Relation->Type === Relations::BelongsToMany
            ? self::PIVOT_LOCAL
            : $Target->identify($Relation->foreign);

         if (array_key_exists($expected, $Result->rows[0]) === false) {
            throw new RuntimeException("ORM relation attachment result is missing key column: {$expected}");
         }
      }

      return [$Result, $Target, $entities];
   }

   /**
    * Build an UPDATE query for one entity.
    */
   private function update (object $Entity, mixed $id): Builder
   {
      if ($id === null) {
         throw new RuntimeException('ORM update requires a primary key value.');
      }

      if ($this->Model->updates === []) {
         throw new RuntimeException('ORM update requires at least one writable column.');
      }

      $Builder = $this->build()->update();

      foreach ($this->Model->updates as $column => $property) {
         $Builder->set(new Identifier($column), $this->Model->read($Entity, $property));
      }

      $Builder->filter(new Identifier($this->Model->key), Operators::Equal, $id);
      $this->output($Builder);

      return $Builder;
   }
}
