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


use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use InvalidArgumentException;
use Stringable;

use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Defaults;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;


/**
 * Column change collected by an ALTER TABLE blueprint.
 */
class Change
{
   // * Config
   public private(set) string $name;
   public private(set) Types $Type;

   // * Data
   public private(set) null|string|Expression $expression = null;
   public null|bool $nullable {
      get => $this->Nullable;
      set (null|bool $value) {
         $this->Nullable = $value;

         if ($value !== null && $this->configured === false) {
            $this->typed = false;
         }
      }
   }
   public private(set) bool $defaulted = false;
   public private(set) bool $dropped = false;
   public private(set) bool $typed = true;
   public null|bool|float|int|string|Stringable|Defaults $default {
      get => $this->Default;
      set (mixed $value) {
         if ($value === Defaults::None) {
            $this->defaulted = false;
            $this->dropped = true;
            $this->Default = null;

            if ($this->configured === false) {
               $this->typed = false;
            }

            return;
         }

         if (
            $value === null
            || is_bool($value)
            || is_float($value)
            || is_int($value)
            || is_string($value)
            || $value instanceof Stringable
         ) {
            $this->defaulted = true;
            $this->dropped = false;
            $this->Default = $value;

            if ($this->configured === false) {
               $this->typed = false;
            }

            return;
         }

         throw new InvalidArgumentException('Schema change default must be scalar, Stringable or Expression.');
      }
   }
   public private(set) int $length = 255;
   public private(set) int $precision = 0;
   public private(set) int $scale = 0;

   // * Metadata
   private null|bool|float|int|string|Stringable $Default = null;
   private null|bool $Nullable = null;
   private bool $configured = false;


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
      $this->configured = true;
      $this->typed = true;

      return $this;
   }

   /**
    * Set numeric precision and scale where supported by the dialect.
    */
   public function size (int $precision, int $scale = 0): self
   {
      $this->precision = $precision;
      $this->scale = $scale;
      $this->configured = true;
      $this->typed = true;

      return $this;
   }

   /**
    * Set the PostgreSQL USING expression for type conversion.
    */
   public function cast (string|Expression $expression): self
   {
      $this->expression = $expression;
      $this->configured = true;
      $this->typed = true;

      return $this;
   }
}
