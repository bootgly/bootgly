<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI;


use const FILTER_VALIDATE_INT;
use function filter_var;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function ltrim;
use function preg_match;
use function strtolower;
use function substr;
use function trim;
use DateRangeError;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;

use Bootgly\ADI\Casters\Modes;


/**
 * Scalar casting vocabulary: converts one value into a target type.
 *
 * Validators answer "can it be cast?"; Casters perform the cast. Every cast
 * names its strictness through `Modes` — `Strict` for human-written input
 * (env vars, config files), `Loose` for values a typed source already produced
 * (database drivers). A value the mode cannot convert is refused with an
 * `InvalidArgumentException` whose message never contains the value, so a
 * caller can surface it without leaking a secret.
 */
enum Casters
{
   /**
    * `bool`.
    *
    * Strict: a `bool`, the ints `0`/`1`, or the words `1 true yes on` /
    * `0 false no off` (case-insensitive, surrounding whitespace ignored).
    * Loose: PHP's `(bool)` of any scalar.
    */
   case Boolean;
   /**
    * `DateTimeImmutable`.
    *
    * Strict: a `DateTimeInterface`, or a string `YYYY-MM-DD`,
    * `YYYY-MM-DD hh:mm:ss[.ffffff]` (default timezone) or the RFC 3339
    * `YYYY-MM-DDThh:mm:ss[.ffffff](Z|±hh:mm)`; impossible dates (`02-30`),
    * times (`25:00`), offsets (`+24:00`) and wall times inside a DST gap are
    * refused. Loose: also a Unix timestamp (`int`/`float`) and any string
    * `new DateTimeImmutable()` parses, except a blank one.
    */
   case DateTime;
   /**
    * `float`.
    *
    * Strict: an `int`, a finite `float`, or a decimal string
    * (`"1.5"`, `".5"`, `"-1.5e-3"`) whose value is finite.
    * Loose: PHP's `(float)` of any scalar.
    */
   case Float;
   /**
    * `int`.
    *
    * Strict: an `int`, or a decimal string (`"42"`, `"-7"`, `"+5"`, no leading
    * zeros, no whitespace) the int range holds. Loose: PHP's `(int)` of any
    * scalar, except a number the int range cannot hold — a numeric string
    * (`is_numeric()`: an oversized decimal, `"1e30"`), a float beyond the range,
    * `INF`, `NAN` — refused instead of saturated or wrapped. Any other string
    * converts as PHP converts it (`"12abc"` → 12), its leading digits unchecked.
    */
   case Integer;
   /**
    * `string`.
    *
    * Strict and Loose: PHP's `(string)` of any scalar.
    */
   case String;

   // * Metadata
   /**
    * 2^63 — the first float beyond the int range (`PHP_INT_MIN` is `-LIMIT`).
    */
   private const float LIMIT = 9.2233720368547758E18;


   /**
    * Cast one value to this type, in the given mode.
    *
    * @throws InvalidArgumentException when the mode cannot convert the value
    *    (the message names the type, never the value).
    */
   public function cast (mixed $value, Modes $Mode): bool|DateTimeImmutable|float|int|string
   {
      // ? Only scalars and date-time objects can be cast
      if (is_scalar($value) === false && ($value instanceof DateTimeInterface) === false) {
         $this->fail();
      }

      // :
      return match ($this) {
         self::Boolean => match (true) {
            is_bool($value) => $value,
            $Mode === Modes::Loose && is_scalar($value) => (bool) $value,
            is_int($value) && ($value === 0 || $value === 1) => $value === 1,
            is_string($value) => match (strtolower(trim($value))) {
               '1', 'true', 'yes', 'on' => true,
               '0', 'false', 'no', 'off' => false,
               default => $this->fail(),
            },
            default => $this->fail(),
         },
         self::DateTime => $this->parse($value, $Mode),
         self::Float => match (true) {
            is_scalar($value) === false => $this->fail(),
            $Mode === Modes::Loose => (float) $value,
            is_int($value) => (float) $value,
            is_float($value) && is_finite($value) => $value,
            // ! Possessive quantifiers: a long digit run never backtracks (no ReDoS).
            is_string($value) && preg_match('/\A[-+]?(?:\d++(?:\.\d*+)?|\.\d++)(?:[eE][-+]?\d++)?\z/', $value) === 1
               && is_finite((float) $value) => (float) $value,
            default => $this->fail(),
         },
         self::Integer => match (true) {
            is_int($value) => $value,
            is_scalar($value) === false => $this->fail(),
            $Mode === Modes::Loose => $this->narrow($value),
            is_string($value) && preg_match('/\A[-+]?\d+\z/', $value) === 1 => match ($integer = filter_var($value, FILTER_VALIDATE_INT)) {
               false => $this->fail(),
               default => $integer,
            },
            default => $this->fail(),
         },
         self::String => match (true) {
            is_scalar($value) => (string) $value,
            default => $this->fail(),
         },
      };
   }

   /**
    * Convert one scalar to `int` the way PHP's `(int)` does, refusing a
    * number (a numeric string or a float) the int range cannot hold.
    *
    * @throws InvalidArgumentException when the value is beyond the int range.
    */
   private function narrow (bool|float|int|string $value): int
   {
      if (is_string($value)) {
         // ?: A canonical decimal (`"42"`, `"-7"`) — the common driver shape — round-trips exactly.
         $integer = (int) $value;
         if ((string) $integer === $value) {
            return $integer;
         }

         // ? A non-numeric string: PHP's leading-digits conversion (`"12abc"` → 12, `"abc"` → 0)
         if (is_numeric($value) === false) {
            return (int) $value;
         }

         // ! PHP numeric strings allow surrounding whitespace.
         $number = trim($value, " \t\n\r\v\f");

         // ? A decimal integer, with or without a zero fraction (`"5.0"`): exact, since PHP
         //   saturates an oversized one to PHP_INT_MAX/MIN and a float loses digits past 2^53.
         if (preg_match('/\A([+-]?[0-9]++)(?:\.0*+)?\z/', $number, $matches) === 1) {
            $number = $matches[1];
            $sign = $number[0] === '-' ? '-' : '';
            $digits = ltrim($number[0] === '+' || $number[0] === '-' ? substr($number, 1) : $number, '0');
            $canonical = $digits === '' ? '0' : "{$sign}{$digits}";
            $integer = (int) $number;

            if ((string) $integer !== $canonical) {
               $this->fail(': it is beyond the int range');
            }

            return $integer;
         }

         // @ A float form (`"1e3"`, `"1.5"`) goes through the float range check.
         $value = (float) $number;
      }

      // ? A float the int range cannot hold wraps or zeroes under `(int)`.
      if (is_float($value) && (is_finite($value) === false || $value < -self::LIMIT || $value >= self::LIMIT)) {
         $this->fail(': it is beyond the int range');
      }

      // :
      return (int) $value;
   }

   /**
    * Convert one value to `DateTimeImmutable`.
    *
    * @throws InvalidArgumentException when the value is not a date-time the mode accepts.
    */
   private function parse (mixed $value, Modes $Mode): DateTimeImmutable
   {
      // ?: Already a date-time.
      if ($value instanceof DateTimeInterface) {
         return DateTimeImmutable::createFromInterface($value);
      }

      if ($Mode === Modes::Loose) {
         // ?: A Unix timestamp the date-time range holds.
         if (is_int($value) || is_float($value)) {
            try {
               return DateTimeImmutable::createFromTimestamp($value);
            }
            catch (DateRangeError) {
               $this->fail();
            }
         }
         // ? A blank string means "now" to PHP: refuse it.
         if (is_string($value) === false || trim($value) === '') {
            $this->fail();
         }

         try {
            $Date = new DateTimeImmutable($value);
         }
         catch (Exception) {
            $this->fail();
         }

         // :
         return $Date;
      }

      // ! Strict: date, SQL date-time or RFC 3339 — one exact shape each.
      if (
         is_string($value) === false
         || preg_match('/\A\d{4}-\d{2}-\d{2}(?:( |T)\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)?)?\z/', $value, $matches) !== 1
      ) {
         $this->fail();
      }

      $separator = $matches[1] ?? '';
      $fraction = ($matches[2] ?? '') !== '' ? '.u' : '';
      $offset = ($matches[3] ?? '') !== '';
      $format = match (true) {
         $separator === '' => '!Y-m-d',
         $separator === ' ' && $offset === false => "!Y-m-d H:i:s{$fraction}",
         $separator === 'T' && $offset => "!Y-m-d\\TH:i:s{$fraction}P",
         default => $this->fail(),
      };

      $Date = DateTimeImmutable::createFromFormat($format, $value);
      // ? An impossible date or time (`2026-02-30`, `25:00:00`) parses with a warning.
      if ($Date === false || DateTimeImmutable::getLastErrors() !== false) {
         $this->fail();
      }
      // ? A wall time the default timezone skips (a DST gap) would shift silently.
      if ($separator === ' ' && $Date->format('Y-m-d H:i:s') !== substr($value, 0, 19)) {
         $this->fail();
      }

      // :
      return $Date;
   }

   /**
    * Refuse a cast without exposing the value.
    *
    * @throws InvalidArgumentException always.
    */
   private function fail (string $reason = ''): never
   {
      $type = match ($this) {
         self::Boolean => 'boolean',
         self::DateTime => 'date-time',
         self::Float => 'float',
         self::Integer => 'integer',
         self::String => 'string',
      };

      throw new InvalidArgumentException("Cannot cast the value to {$type}{$reason}.");
   }
}
