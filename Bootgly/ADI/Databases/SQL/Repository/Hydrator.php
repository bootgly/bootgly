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


use function array_key_exists;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function var_export;
use InvalidArgumentException;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

use Bootgly\ADI\Casters;
use Bootgly\ADI\Casters\Modes;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL\Model;


/**
 * ORM row hydrator.
 */
class Hydrator
{
   // * Config
   public private(set) Model $Model;
   public private(set) Identity $Identity;

   // * Data
   // ...

   // * Metadata
   /** @var array<string,true> */
   private array $readonly = [];


   public function __construct (Model $Model, Identity $Identity)
   {
      // * Config
      $this->Model = $Model;
      $this->Identity = $Identity;

      // * Metadata
      foreach ($Model->columns as $property) {
         if ($Model->Reflections[$property]->isReadOnly()) {
            $this->readonly[$property] = true;
         }
      }
   }

   /**
    * Check whether one result row can hydrate into an existing entity.
    *
    * False when the row misses a required column, carries a value its property
    * type refuses, or differs from a readonly property the entity already holds.
    *
    * @param array<string,mixed> $row
    */
   public function check (object $Entity, array $row): bool
   {
      try {
         $this->filter($Entity, $this->extract($row)[0]);
      }
      catch (RuntimeException) {
         return false;
      }

      // :
      return true;
   }

   /**
    * Hydrate result rows into mapped entity objects.
    *
    * @return array<int,object>
    */
   public function hydrate (Result $Result): array
   {
      // ! Hydrated entities.
      $entities = [];

      // @@ Result rows.
      foreach ($Result->rows as $row) {
         $Entity = null;
         $key = $row[$this->Model->key] ?? null;

         // ? Identity reuse.
         if ($key !== null) {
            $Entity = $this->Identity->fetch($this->Model->class, $key);
         }

         $Entity ??= $this->Model->create();

         // ! Only the columns this row actually carried. A property left at its
         //   class default reads exactly like one the caller chose, so an UPDATE
         //   built from the whole map writes those defaults over stored data.
         [$values, $carried] = $this->extract($row);

         foreach ($this->filter($Entity, $values) as $property => $value) {
            $this->Model->write($Entity, $property, $value);
         }

         $this->Identity->record($Entity, $carried);

         // @ Identity store.
         if ($key !== null) {
            $this->Identity->store($this->Model->class, $key, $Entity);
         }

         $entities[] = $Entity;
      }

      // : Entities.
      return $entities;
   }

   /**
    * Extract the cast property values and the carried columns of one row.
    *
    * @param array<string,mixed> $row
    * @return array{0:array<string,mixed>,1:array<string,true>}
    */
   private function extract (array $row): array
   {
      $values = [];
      $carried = [];

      foreach ($this->Model->columns as $column => $property) {
         // ? Required columns.
         if (array_key_exists($column, $row) === false) {
            if ($this->Model->definitions[$column]->nullable || $this->Model->definitions[$column]->generated) {
               continue;
            }

            throw new RuntimeException("ORM result row is missing required column: {$column}");
         }

         $values[$property] = $this->cast($row[$column], $this->Model->Reflections[$property]);
         $carried[$column] = true;
      }

      // :
      return [$values, $carried];
   }

   /**
    * Filter out the readonly values an entity already holds, refusing a different one.
    *
    * @param array<string,mixed> $values
    * @return array<string,mixed>
    */
   private function filter (object $Entity, array $values): array
   {
      foreach ($this->readonly as $property => $_) {
         $Reflection = $this->Model->Reflections[$property];

         if (array_key_exists($property, $values) === false || $Reflection->isInitialized($Entity) === false) {
            continue;
         }

         $current = $Reflection->getValue($Entity);
         $value = $values[$property];

         // ? Objects compare by state: a DateTimeImmutable decoded again is a new instance.
         if ($current !== $value && (is_object($current) === false || $current != $value)) {
            throw new RuntimeException("ORM cannot overwrite a readonly property: {$this->Model->class}::\${$property}");
         }

         unset($values[$property]);
      }

      // :
      return $values;
   }

   /**
    * Cast one decoded value to the declared property type.
    */
   private function cast (mixed $value, ReflectionProperty $Property): mixed
   {
      $Type = $Property->getType();

      if ($value === null) {
         if ($Type instanceof ReflectionNamedType && $Type->allowsNull() === false) {
            throw new RuntimeException("ORM cannot assign null to non-nullable property: {$Property->getName()}");
         }

         return null;
      }

      if ($Type instanceof ReflectionNamedType) {
         if ($Type->isBuiltin() === false) {
            return $value;
         }

         if (
            is_bool($value) === false
            && is_float($value) === false
            && is_int($value) === false
            && is_string($value) === false
         ) {
            return $value;
         }

         $type = $Type->getName();
         // ?: Already the property type — the common driver shape needs no cast.
         if (
            ($type === 'int' && is_int($value))
            || ($type === 'string' && is_string($value))
            || ($type === 'bool' && is_bool($value))
            || ($type === 'float' && is_float($value))
         ) {
            return $value;
         }

         // @ Driver values follow PHP's casts (`Modes::Loose`).
         if ($type !== 'int') {
            return match ($type) {
               'bool' => Casters::Boolean->cast($value, Modes::Loose),
               'float' => Casters::Float->cast($value, Modes::Loose),
               'string' => Casters::String->cast($value, Modes::Loose),
               default => $value,
            };
         }

         // @ Only an int can be refused: the MySQL decoder hands back an exact
         //   decimal string for a `BIGINT UNSIGNED` past 2^63, and PHP saturates
         //   it to PHP_INT_MAX — a key that matches no row, so the next save()
         //   targets nothing and reports success. Same contract the write side
         //   already applies to a generated key.
         try {
            return Casters::Integer->cast($value, Modes::Loose);
         }
         catch (InvalidArgumentException) {
            $property = "{$Property->getDeclaringClass()->getName()}::\${$Property->getName()}";
            // ! A float renders exactly and without PHP's NAN-to-string warning.
            $value = is_float($value) ? var_export($value, true) : $value;

            throw new RuntimeException(
               "ORM cannot hydrate a value beyond PHP_INT_MAX into an int property: {$property} ({$value}) — declare it as null|int|string."
            );
         }
      }

      return $value;
   }
}
