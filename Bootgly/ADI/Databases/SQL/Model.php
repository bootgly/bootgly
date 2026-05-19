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


use function count;
use function is_a;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;

use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Relation;
use Bootgly\ADI\Databases\SQL\Model\Table;


/**
 * Compiled ORM entity mapping metadata.
 */
class Model
{
   // * Config
   /** @var class-string */
   public private(set) string $class;
   public private(set) string $table;
   public private(set) string $key;
   public private(set) string $keyProperty;
   public private(set) bool $generated;
   /** @var array<string,string> */
   public private(set) array $columns;
   /** @var array<string,string> */
   public private(set) array $properties;
   /** @var array<string,Column> */
   public private(set) array $definitions;
   /** @var array<string,string> */
   public private(set) array $insertions;
   /** @var array<string,string> */
   public private(set) array $updates;
   /** @var array<string,Relation> */
   public private(set) array $relations;
   /** @var array<string,string> */
   public private(set) array $relationProperties;
   /** @var array<string,ReflectionProperty> */
   public private(set) array $Reflections;

   // * Data
   // ...

   // * Metadata
   /** @var ReflectionClass<object> */
   private ReflectionClass $Class;


   /**
    * @param class-string $class
    * @param array<string,string> $columns
    * @param array<string,string> $properties
    * @param array<string,Column> $definitions
    * @param array<string,string> $insertions
    * @param array<string,string> $updates
    * @param array<string,Relation> $relations
    * @param array<string,string> $relationProperties
    * @param array<string,ReflectionProperty> $Reflections
    * @param ReflectionClass<object> $Class
    */
   public function __construct (
      string $class,
      string $table,
      string $key,
      string $keyProperty,
      bool $generated,
      array $columns,
      array $properties,
      array $definitions,
      array $insertions,
      array $updates,
      array $relations,
      array $relationProperties,
      array $Reflections,
      ReflectionClass $Class
   )
   {
      // * Config
      $this->class = $class;
      $this->table = $table;
      $this->key = $key;
      $this->keyProperty = $keyProperty;
      $this->generated = $generated;
      $this->columns = $columns;
      $this->properties = $properties;
      $this->definitions = $definitions;
      $this->insertions = $insertions;
      $this->updates = $updates;
      $this->relations = $relations;
      $this->relationProperties = $relationProperties;
      $this->Reflections = $Reflections;

      // * Data
      // ...

      // * Metadata
      $this->Class = $Class;
   }

   /**
    * Compile mapping metadata for one entity class.
    *
    * @param class-string $class
    */
   public static function reflect (string $class): self
   {
      // ! Entity reflection.
      $Class = new ReflectionClass($class);

      // ? Table mapping.
      $Tables = $Class->getAttributes(Table::class);

      if (count($Tables) !== 1) {
         throw new InvalidArgumentException("ORM entity requires one Table attribute: {$class}");
      }

      /** @var Table $Table */
      $Table = $Tables[0]->newInstance();

      // ! Metadata buffers.
      $columns = [];
      $properties = [];
      $definitions = [];
      $insertions = [];
      $updates = [];
      $relations = [];
      $relationProperties = [];
      $Reflections = [];
      $key = null;
      $keyProperty = null;
      $generated = false;

      // @ Property attributes.
      foreach ($Class->getProperties() as $Property) {
         $name = $Property->getName();
         $Keys = $Property->getAttributes(Key::class);
         $Columns = $Property->getAttributes(Column::class);
         $Relations = $Property->getAttributes(Relation::class);

         if ($Keys !== [] && $Columns !== []) {
            throw new InvalidArgumentException("ORM property cannot have both Key and Column attributes: {$class}::\${$name}");
         }

         if ($Keys !== [] || $Columns !== []) {
            if (count($Keys) + count($Columns) !== 1) {
               throw new InvalidArgumentException("ORM property accepts one mapping attribute: {$class}::\${$name}");
            }

            /** @var Column $Column */
            $Column = $Keys !== []
               ? $Keys[0]->newInstance()
               : $Columns[0]->newInstance();
            $column = $Column->name ?? $name;

            if (isset($columns[$column])) {
               throw new InvalidArgumentException("ORM column is mapped twice: {$column}");
            }

            $columns[$column] = $name;
            $properties[$name] = $column;
            $definitions[$column] = $Column;
            $Reflections[$name] = $Property;

            if ($Column->insert) {
               $insertions[$column] = $name;
            }

            if ($Column->update) {
               $updates[$column] = $name;
            }

            if ($Keys !== []) {
               if ($key !== null) {
                  throw new InvalidArgumentException("ORM entity accepts one primary key: {$class}");
               }

               $key = $column;
               $keyProperty = $name;
               $generated = $Column->generated;
            }
         }

         foreach ($Relations as $Attribute) {
            /** @var Relation $Relation */
            $Relation = $Attribute->newInstance();
            $relation = $Relation->name ?? $name;

            if (isset($relations[$relation])) {
               throw new InvalidArgumentException("ORM relation is mapped twice: {$relation}");
            }

            $relations[$relation] = $Relation;
            $relationProperties[$relation] = $name;
            $Reflections[$name] = $Property;
         }
      }

      // ? Primary key presence.
      if ($key === null || $keyProperty === null) {
         throw new InvalidArgumentException("ORM entity requires one primary key: {$class}");
      }

      // : Compiled model.
      return new self(
         $class,
         $Table->name,
         $key,
         $keyProperty,
         $generated,
         $columns,
         $properties,
         $definitions,
         $insertions,
         $updates,
         $relations,
         $relationProperties,
         $Reflections,
         $Class
      );
   }

   /**
    * Create one entity instance for hydration.
    */
   public function create (): object
   {
      $Constructor = $this->Class->getConstructor();

      if ($Constructor === null || $Constructor->getNumberOfRequiredParameters() === 0) {
         return $this->Class->newInstance();
      }

      return $this->Class->newInstanceWithoutConstructor();
   }

   /**
    * Validate whether an object matches this model entity class.
    */
   public function validate (object $Entity): void
   {
      if (is_a($Entity, $this->class) === false) {
         throw new InvalidArgumentException("ORM entity does not match model: {$this->class}");
      }
   }

   /**
    * Resolve one property name to its mapped SQL column.
    */
   public function identify (string $name): string
   {
      return $this->properties[$name] ?? $name;
   }

   /**
    * Read one mapped property value.
    */
   public function read (object $Entity, string $property): mixed
   {
      $Reflection = $this->Reflections[$property] ?? null;

      if ($Reflection === null) {
         throw new InvalidArgumentException("ORM property is not mapped: {$property}");
      }

      if ($Reflection->isInitialized($Entity) === false) {
         return null;
      }

      return $Reflection->getValue($Entity);
   }

   /**
    * Resolve one SQL column to its mapped property name.
    */
   public function resolve (string $column): string
   {
      return $this->columns[$column] ?? $column;
   }

   /**
    * Write one mapped property value.
    */
   public function write (object $Entity, string $property, mixed $value): void
   {
      $Reflection = $this->Reflections[$property] ?? null;

      if ($Reflection === null) {
         throw new InvalidArgumentException("ORM property is not mapped: {$property}");
      }

      $Reflection->setValue($Entity, $value);
   }
}
