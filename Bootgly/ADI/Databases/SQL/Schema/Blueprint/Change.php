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


use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;


/**
 * Column type change collected by an ALTER TABLE blueprint.
 */
class Change
{
   // * Config
   public private(set) string $name;
   public private(set) Types $Type;

   // * Data
   public private(set) null|string|Expression $expression = null;
   public private(set) int $length = 255;
   public private(set) int $precision = 0;
   public private(set) int $scale = 0;

   // * Metadata
   // ...


   public function __construct (string $name, Types $Type)
   {
      // * Config
      $this->name = $name;
      $this->Type = $Type;
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
    * Set the PostgreSQL USING expression for type conversion.
    */
   public function cast (string|Expression $expression): self
   {
      $this->expression = $expression;

      return $this;
   }
}