<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Code\__String;


use const FILTER_VALIDATE_INT;
use const PHP_INT_MAX;
use function addcslashes;
use function count;
use function filter_var;
use function floor;
use function intdiv;
use function log;
use function ltrim;
use function max;
use function min;
use function pow;
use function preg_match;
use function round;
use function rtrim;
use function strlen;
use function strtolower;
use function substr;
use InvalidArgumentException;


/**
 * Byte sizes as human-readable strings, both ways.
 *
 * Every unit is a binary multiple (1 KB = 1024 B): `format()` writes the
 * largest unit that keeps the value at or above 1, and `parse()` reads it back.
 */
class Bytes
{
   /**
    * The units `format()` writes, one per power of 1024.
    *
    * @var array<int,string>
    */
   public const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

   /**
    * The longest size string `parse()` reads, in bytes.
    */
   public const int LIMIT = 64;

   /**
    * The unit spellings `parse()` accepts, each mapped to its power of 1024.
    * Lookup is case-insensitive; a bare number is a count of bytes.
    *
    * @var array<string,int>
    */
   private const array POWERS = [
      '' => 0, 'b' => 0,
      'k' => 1, 'kb' => 1, 'kib' => 1,
      'm' => 2, 'mb' => 2, 'mib' => 2,
      'g' => 3, 'gb' => 3, 'gib' => 3,
      't' => 4, 'tb' => 4, 'tib' => 4,
      'p' => 5, 'pb' => 5, 'pib' => 5,
      'e' => 6, 'eb' => 6, 'eib' => 6,
      'z' => 7, 'zb' => 7, 'zib' => 7,
      'y' => 8, 'yb' => 8, 'yib' => 8
   ];


   /**
    * Format a count of bytes with the largest unit that keeps it at or above 1
    * (`1536` → `"1.5 KB"`), up to `YB`. A count below 1 stays in `B`; a
    * negative count is formatted as `0 B`.
    *
    * @param float|int $bytes The count of bytes.
    * @param int $precision Decimal digits kept by `round()`.
    *
    * @return string The rounded value, a space and the unit.
    */
   public static function format (float|int $bytes, int $precision = 2): string
   {
      // !
      // ! A negative count, negative zero included, is 0
      $bytes = $bytes > 0 ? (float) $bytes : 0.0;
      $pow = (int) max(0, min(
         floor(($bytes ? log($bytes) : 0) / log(1024)),
         count(self::UNITS) - 1
      ));

      // @
      $value = round($bytes / pow(1024, $pow), $precision);
      $unit = self::UNITS[$pow];

      // :
      return "{$value} {$unit}";
   }

   /**
    * Parse a human-readable byte size into a count of bytes (`"1.5 KB"` →
    * `1536`). It reads every unit `format()` writes: a formatted size reads
    * back as the rounded value it shows — not the original count — unless
    * that value passes `PHP_INT_MAX` (`format(PHP_INT_MAX)` is `"8 EB"`) or
    * PHP printed it in exponent form (`"1.0E-5 B"`).
    *
    * Accepts a non-negative decimal number, optional spaces or tabs and a
    * unit: `B`, `K`/`KB`/`KiB` through `Y`/`YB`/`YiB`, in any letter case; a
    * bare number is a count of bytes. Every unit is a binary multiple. The
    * arithmetic is exact (no floating point) and a fractional result is
    * rounded down to whole bytes.
    *
    * @param string $size The size, e.g. `"512"`, `"64K"`, `"1.5 GB"`, `"2 GiB"`,
    *        at most `Bytes::LIMIT` bytes long.
    *
    * @return int The count of bytes.
    *
    * @throws InvalidArgumentException When the size is longer than
    *         `Bytes::LIMIT`, empty, negative or malformed, names an unknown
    *         unit, or exceeds `PHP_INT_MAX` bytes.
    */
   public static function parse (string $size): int
   {
      // ! Messages quote the input bounded, with backslashes, quotes, control
      //   and non-ASCII bytes escaped
      $quoted = addcslashes(substr($size, 0, self::LIMIT), "\0..\37\\'\177..\377");

      $limit = self::LIMIT;

      // ?
      if (strlen($size) > $limit) {
         throw new InvalidArgumentException("Byte size longer than {$limit} bytes: '{$quoted}...'.");
      }
      if (preg_match('/^[ \t]*(\d+)(?:\.(\d+))?[ \t]*([a-zA-Z]*)[ \t]*$/D', $size, $matches) !== 1) {
         throw new InvalidArgumentException("Invalid byte size '{$quoted}'.");
      }

      // !
      [, $digits, $fraction, $unit] = $matches;
      $pow = self::POWERS[strtolower($unit)]
         ?? throw new InvalidArgumentException("Unknown byte unit '{$unit}' in '{$quoted}'.");
      $shift = 10 * $pow;
      // ! The whole part; `false` when its digits alone overflow an int
      $digits = ltrim($digits, '0');
      $whole = $digits === '' ? 0 : filter_var($digits, FILTER_VALIDATE_INT);

      // ? A shift of 64 or more yields 0: any whole unit past `EB` overflows
      if ($whole === false || $whole > PHP_INT_MAX >> $shift) {
         throw new InvalidArgumentException("Byte size '{$quoted}' exceeds PHP_INT_MAX.");
      }

      // @ The whole part, exact
      $bytes = $whole << $shift;

      // @@ The fractional part, exact: floor(0.<fraction> × 2^shift), one
      //    decimal doubling per bit — its carry is the next bit
      $fraction = rtrim($fraction, '0');
      $part = 0;
      for ($bit = 0; $bit < $shift; $bit++) {
         // ? No digit left: every remaining bit is 0
         if ($fraction === '') {
            $remaining = $shift - $bit;

            // ?
            if ($part > PHP_INT_MAX >> $remaining) {
               throw new InvalidArgumentException("Byte size '{$quoted}' exceeds PHP_INT_MAX.");
            }

            $part <<= $remaining;
            break;
         }

         $carry = 0;
         for ($index = strlen($fraction) - 1; $index >= 0; $index--) {
            $double = (int) $fraction[$index] * 2 + $carry;
            $fraction[$index] = (string) ($double % 10);
            $carry = intdiv($double, 10);
         }
         $fraction = rtrim($fraction, '0');

         // ? Each further bit at least doubles the part
         if ($part > PHP_INT_MAX >> 1) {
            throw new InvalidArgumentException("Byte size '{$quoted}' exceeds PHP_INT_MAX.");
         }

         $part = ($part << 1) | $carry;
      }

      // : `$part < 2^shift` and `$whole <= PHP_INT_MAX >> shift`, so the sum
      //   stays below 2^63 — it cannot overflow
      return $bytes + $part;
   }
}
