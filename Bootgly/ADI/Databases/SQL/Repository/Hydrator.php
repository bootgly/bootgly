<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


use function array_key_exists;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
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
         }

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
    * Cast one scalar value according to a mapped property type.
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
            'int' => (int) $value,
            'string' => (string) $value,
            default => $value,
         };
      }

      return $value;
   }
}
