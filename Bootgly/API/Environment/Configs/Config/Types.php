<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment\Configs\Config;


use const FILTER_VALIDATE_INT;
use function filter_var;
use function in_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function strtolower;
use function trim;
use InvalidArgumentException;


/**
 * Strict scalar parsers for config values.
 *
 * Invalid values throw instead of relying on PHP's loose scalar coercions.
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
    * @throws InvalidArgumentException when the value is ambiguous or invalid.
    */
   public function cast (string|int|float|bool $value): string|int|float|bool
   {
      return match ($this) {
         self::Integer => match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/\A[-+]?\d+\z/', $value) === 1 => match (true) {
               filter_var($value, FILTER_VALIDATE_INT) !== false => filter_var($value, FILTER_VALIDATE_INT),
               default => self::fail('integer', $value),
            },
            default => self::fail('integer', $value),
         },
         self::Float => match (true) {
            is_int($value) => (float) $value,
            is_float($value) && is_finite($value) => $value,
            is_string($value) && preg_match('/\A[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?\z/', $value) === 1 => match (true) {
               is_finite((float) $value) => (float) $value,
               default => self::fail('float', $value),
            },
            default => self::fail('float', $value),
         },
         self::Boolean => match (true) {
            is_bool($value) => $value,
            is_int($value) && in_array($value, [0, 1], true) => $value === 1,
            is_string($value) => match (strtolower(trim($value))) {
               '1', 'true', 'yes', 'on' => true,
               '0', 'false', 'no', 'off' => false,
               default => self::fail('boolean', $value),
            },
            default => self::fail('boolean', $value),
         },
         self::String => (string) $value,
      };
   }

   /**
    * Throw a parser error without exposing the rejected value.
    *
    * @throws InvalidArgumentException always.
    */
   private static function fail (string $type, string|int|float|bool $value): never
   {
      throw new InvalidArgumentException("Invalid {$type} config value.");
   }
}
