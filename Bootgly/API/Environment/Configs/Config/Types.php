<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment\Configs\Config;


use function strtolower;
use InvalidArgumentException;
use SensitiveParameter;

use Bootgly\ADI\Casters;
use Bootgly\ADI\Casters\Modes;


/**
 * Strict scalar parsers for config values.
 *
 * Invalid values throw instead of relying on PHP's loose scalar coercions:
 * each type is the ADI `Casters` counterpart in `Modes::Strict`.
 */
enum Types
{
   case Integer;
   case Float;
   case Boolean;
   case String;

   /**
    * Parse a scalar value according to this config type.
    *
    * The value is a sensitive parameter: exception traces never carry it.
    *
    * @throws InvalidArgumentException when the value is ambiguous or invalid.
    */
   public function cast (#[SensitiveParameter] string|int|float|bool $value): string|int|float|bool
   {
      // ! The strict ADI caster of this config type.
      $Caster = match ($this) {
         self::Integer => Casters::Integer,
         self::Float => Casters::Float,
         self::Boolean => Casters::Boolean,
         self::String => Casters::String,
      };

      try {
         /** @var string|int|float|bool $cast Scalar casters only. */
         $cast = $Caster->cast($value, Modes::Strict);
      }
      catch (InvalidArgumentException) {
         self::fail(strtolower($this->name));
      }

      // :
      return $cast;
   }

   /**
    * Throw a parser error without exposing the rejected value.
    *
    * @throws InvalidArgumentException always.
    */
   private static function fail (string $type): never
   {
      throw new InvalidArgumentException("Invalid {$type} config value.");
   }
}
