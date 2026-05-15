<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema\Blueprint;


use BackedEnum;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;


/**
 * Column definition collected by a table blueprint.
 */
class Column
{
   // * Config
   public private(set) string $table;
   public private(set) string $name;
   public private(set) Types $Type;

   // * Data
   public private(set) bool $nullable = false;
   public private(set) bool $generated = false;
   public private(set) bool $primary = false;
   public private(set) bool $unique = false;
   public private(set) bool $defaulted = false;
   public private(set) null|bool|float|int|string|Stringable|Expression $default = null;
   /** Default string length; dialect compilers ignore it for fixed-size types. */
   public private(set) int $length = 255;
   public private(set) int $precision = 0;
   public private(set) int $scale = 0;
   public private(set) null|Reference $Reference = null;
   /** @var array<int,string|Expression> */
   public private(set) array $checks = [];

   // * Metadata
   // ...


   public function __construct (string $table, string $name, Types $Type)
   {
      // * Config
      $this->table = $table;
      $this->name = $name;
      $this->Type = $Type;
   }

   /**
    * Allow NULL values.
    */
   public function allow (): self
   {
      $this->nullable = true;

      return $this;
   }

   /**
    * Require non-NULL values.
    */
   public function require (): self
   {
      $this->nullable = false;

      return $this;
   }

   /**
    * Set a type length where supported by the dialect.
    */
   public function limit (int $length): self
   {
      $this->length = $length;

      return $this;
   }

   /**
    * Set numeric precision and scale where supported by the dialect.
    */
   public function size (int $precision, int $scale = 0): self
   {
      $this->precision = $precision;
      $this->scale = $scale;

      return $this;
   }

   /**
    * Set a database default expression or literal value.
    */
   public function default (null|bool|float|int|string|Stringable|Expression $value): self
   {
      $this->defaulted = true;
      $this->default = $value;

      return $this;
   }

   /**
    * Generate values with the dialect's identity/autoincrement mechanism.
    */
   public function generate (): self
   {
      $this->generated = true;

      return $this;
   }

   /**
    * Add one column constraint.
    */
   public function constrain (Keys $Key): self
   {
      match ($Key) {
         Keys::Primary => $this->primary = true,
         Keys::Unique => $this->unique = true,
      };

      return $this;
   }

   /**
    * Add one CHECK expression.
    */
   public function check (string|Expression $expression): self
   {
      $this->checks[] = $expression;

      return $this;
   }

   /**
    * Add one inline foreign key reference.
    */
   public function reference (
      BackedEnum|Stringable|string $Table,
      BackedEnum|Stringable|string $Column = 'id',
      null|string $name = null
   ): self
   {
      $this->Reference = new Reference(
         $this->table,
         $this->name,
         $this->normalize($Table),
         $this->normalize($Column),
         $name
      );

      return $this;
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
