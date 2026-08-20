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
use function is_string;
use function ltrim;
use function preg_match;
use function substr;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

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
   // ...


   public function __construct (Model $Model, Identity $Identity)
   {
      // * Config
      $this->Model = $Model;
      $this->Identity = $Identity;
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
         $carried = [];

         foreach ($this->Model->columns as $column => $property) {
            // ? Required columns.
            if (array_key_exists($column, $row) === false) {
               if ($this->Model->definitions[$column]->nullable || $this->Model->definitions[$column]->generated) {
                  continue;
               }

               throw new RuntimeException("ORM result row is missing required column: {$column}");
            }

            $value = $this->cast($row[$column], $this->Model->Reflections[$property]);
            $this->Model->write($Entity, $property, $value);
            $carried[$column] = true;
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
    * Narrow one decoded value into an int property without losing precision.
    */
   private function narrow (bool|float|int|string $value, ReflectionProperty $Property): int
   {
      // ? Only an exact decimal string can carry more than an int holds. The
      //   MySQL decoder hands one back for a `BIGINT UNSIGNED` past 2^63, and
      //   PHP saturates it to PHP_INT_MAX — a key that matches no row, so the
      //   next save() targets nothing and reports success. Same contract the
      //   write side already applies to a generated key.
      if (is_string($value) && preg_match('/^[+-]?[0-9]+$/', $value) === 1) {
         $sign = $value[0] === '-' ? '-' : '';
         $digits = ltrim($value[0] === '+' || $value[0] === '-' ? substr($value, 1) : $value, '0');
         $canonical = $digits === '' ? '0' : "{$sign}{$digits}";

         if ((string) (int) $value !== $canonical) {
            $property = "{$Property->getDeclaringClass()->getName()}::\${$Property->getName()}";

            throw new RuntimeException(
               "ORM cannot hydrate a value beyond PHP_INT_MAX into an int property: {$property} ({$value}) — declare it as null|int|string."
            );
         }
      }

      // :
      return (int) $value;
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

         return match ($Type->getName()) {
            'bool' => (bool) $value,
            'float' => (float) $value,
            'int' => $this->narrow($value, $Property),
            'string' => (string) $value,
            default => $value,
         };
      }

      return $value;
   }
}
