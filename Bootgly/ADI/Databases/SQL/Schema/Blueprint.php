<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema;


use function array_map;
use function is_array;
use BackedEnum;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Change;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Column;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\DefaultValue;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Index;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Nullability;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Reference;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint\Rename;


/**
 * Table blueprint collected before dialect-specific DDL compilation.
 */
class Blueprint
{
   // * Config
   public private(set) string $table;

   // * Data
   /** @var array<int,Column> */
   public private(set) array $columns = [];
   /** @var array<int,Change> */
   public private(set) array $changes = [];
   /** @var array<int,DefaultValue> */
   public private(set) array $defaults = [];
   /** @var array<int,string> */
   public private(set) array $drops = [];
   /** @var array<int,Nullability> */
   public private(set) array $nullabilities = [];
   /** @var array<int,Reference> */
   public private(set) array $references = [];
   /** @var array<int,Rename> */
   public private(set) array $renames = [];

   // * Metadata
   // ...


   public function __construct (BackedEnum|Stringable|string $Table)
   {
      // * Config
      $this->table = $this->normalize($Table);
   }

   /**
    * Add one column to the table blueprint.
    */
   public function add (BackedEnum|Stringable|string $Column, Types $Type = Types::Text): Column
   {
      $Column = new Column($this->table, $this->normalize($Column), $Type);
      $this->columns[] = $Column;

      return $Column;
   }

   /**
    * Remove one column in an ALTER TABLE blueprint.
    */
   public function remove (BackedEnum|Stringable|string $Column): self
   {
      $this->drops[] = $this->normalize($Column);

      return $this;
   }

   /**
    * Change one column type in an ALTER TABLE blueprint.
    */
   public function change (BackedEnum|Stringable|string $Column, Types $Type): Change
   {
      $Change = new Change($this->normalize($Column), $Type);
      $this->changes[] = $Change;

      return $Change;
   }

   /**
    * Rename one column in an ALTER TABLE blueprint.
    */
   public function rename (BackedEnum|Stringable|string $From, BackedEnum|Stringable|string $To): self
   {
      $this->renames[] = new Rename($this->normalize($From), $this->normalize($To));

      return $this;
   }

   /**
    * Allow NULL values for one existing column.
    */
   public function allow (BackedEnum|Stringable|string $Column): self
   {
      $this->nullabilities[] = new Nullability($this->normalize($Column), true);

      return $this;
   }

   /**
    * Require non-NULL values for one existing column.
    */
   public function require (BackedEnum|Stringable|string $Column): self
   {
      $this->nullabilities[] = new Nullability($this->normalize($Column), false);

      return $this;
   }

   /**
    * Set the default value for one existing column.
    */
   public function default (
      BackedEnum|Stringable|string $Column,
      null|bool|float|int|string|Stringable|Expression $value
   ): self
   {
      $this->defaults[] = new DefaultValue($this->normalize($Column), $value);

      return $this;
   }

   /**
    * Drop the default value for one existing column.
    */
   public function undefault (BackedEnum|Stringable|string $Column): self
   {
      $this->defaults[] = new DefaultValue($this->normalize($Column), dropped: true);

      return $this;
   }

   /**
    * Add one table index definition.
    *
    * @param BackedEnum|Stringable|string|array<int,BackedEnum|Stringable|string> $Columns
    */
   public function index (BackedEnum|Stringable|string|array $Columns, null|string $name = null, bool $unique = false): Index
   {
      $columns = is_array($Columns)
         ? array_map(fn (BackedEnum|Stringable|string $Column): string => $this->normalize($Column), $Columns)
         : [$this->normalize($Columns)];

      return new Index($this->table, $columns, $name, $unique);
   }

   /**
    * Add one table-level foreign key reference.
    */
   public function reference (
      BackedEnum|Stringable|string $Column,
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Reference = 'id',
      null|string $name = null
   ): Reference
   {
      $Reference = new Reference(
         $this->table,
         $this->normalize($Column),
         $this->normalize($Table),
         $this->normalize($Reference),
         $name
      );
      $this->references[] = $Reference;

      return $Reference;
   }

   /**
    * Normalize one backed enum, Stringable or scalar identifier name.
    */
   private function normalize (BackedEnum|Stringable|string $Identifier): string
   {
      if ($Identifier instanceof BackedEnum) {
         return (string) $Identifier->value;
      }

      return (string) $Identifier;
   }
}
