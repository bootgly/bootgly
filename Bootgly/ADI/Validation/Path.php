<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Validation;


use function array_key_exists;
use function implode;
use function is_array;
use function ord;
use function preg_match;
use function preg_replace_callback;
use function sprintf;
use function str_replace;
use function strlen;


/**
 * A rule key read as a path into a nested source.
 *
 * Segments are separated by `.`; a segment that is exactly `*` is a wildcard
 * (every entry of the array at that level). A backslash escapes the next
 * `.`, `*` or `\`, so `a\.b` is the single key `a.b` and `\*` the literal
 * key `*`; any other backslash is kept as it is. Parsing never fails: an
 * empty segment (`a..b`) is the empty key `''`.
 *
 * A key without `.`, `*` or `\` is one segment — the flat key it always was.
 */
class Path
{
   // * Config
   /**
    * The rule key as written.
    */
   public private(set) string $key;

   // * Data
   // ...

   // * Metadata
   /**
    * The unescaped segments in order; `null` marks a wildcard (`*`).
    *
    * @var array<int,null|string>
    */
   public private(set) array $segments;
   /**
    * Whether any segment is a wildcard.
    */
   public private(set) bool $wildcard;
   /**
    * The escaped path of the segments before the first wildcard — the
    * container the first wildcard expands (`items` for `items.*.id`, `''`
    * for a root wildcard). Equal to the key when there is no wildcard.
    */
   public private(set) string $prefix;


   /**
    * Parse a rule key into its segments.
    *
    * @param string $key The rule key (`email`, `address.city`, `items.*.id`, `a\.b`).
    */
   public function __construct (string $key)
   {
      // * Config
      $this->key = $key;

      // * Metadata
      $segments = [];
      $prefix = [];
      $wildcard = false;

      // @@ Scan once: escapes, separators, wildcard segments
      $segment = '';
      $escaped = false;
      $length = strlen($key);
      for ($index = 0; $index <= $length; $index++) {
         $character = $index < $length ? $key[$index] : '.';

         if ($character === '\\' && $index + 1 < $length) {
            $next = $key[$index + 1];

            // ? Known escape: keep the next character literally
            if ($next === '.' || $next === '*' || $next === '\\') {
               $segment .= $next;
               $escaped = true;
               $index++;

               continue;
            }
         }

         // ? Not a separator (the end of the key closes the last segment)
         if ($character !== '.') {
            $segment .= $character;

            continue;
         }

         // @ Segment boundary
         $raw = $segment === '*' && $escaped === false;
         $segments[] = $raw ? null : $segment;

         if ($raw) {
            $wildcard = true;
         }
         else if ($wildcard === false) {
            $prefix[] = self::escape($segment);
         }

         $segment = '';
         $escaped = false;
      }

      $this->segments = $segments;
      $this->wildcard = $wildcard;
      $this->prefix = $wildcard ? implode('.', $prefix) : $key;
   }

   /**
    * Escape one source key as a path segment.
    *
    * `\` becomes `\\`, `.` becomes `\.` and a key that is exactly `*`
    * becomes `\*`, so parsing the joined result gives the keys back. A key
    * that is not valid UTF-8 (a form or query field name) has each byte from
    * `0x80` up written as `\xHH`, so error paths stay JSON-encodable; such a
    * segment is for display and does not parse back to the raw key.
    *
    * @param int|string $segment The source key.
    *
    * @return string The escaped segment.
    */
   public static function escape (int|string $segment): string
   {
      $segment = (string) $segment;

      // ?: The literal key `*` must not read back as a wildcard
      if ($segment === '*') {
         return '\\*';
      }

      $escaped = str_replace(['\\', '.'], ['\\\\', '\\.'], $segment);

      // ?: Invalid UTF-8: bytes as `\xHH`
      if (preg_match('//u', $escaped) !== 1) {
         return (string) preg_replace_callback('/[\x80-\xFF]/', static fn (array $match): string => sprintf('\\x%02X', ord($match[0])), $escaped);
      }

      // :
      return $escaped;
   }

   /**
    * Look the path up in a source.
    *
    * A wildcard segment never resolves, and a missing key or a non-array
    * container on the way makes the whole path missing.
    *
    * @param array<array-key,mixed> $source The data to read.
    *
    * @return array{0:bool,1:mixed} Whether the path exists, and its value (`null` when missing).
    */
   public function resolve (array $source): array
   {
      $node = $source;

      // @@ Walk one segment at a time
      foreach ($this->segments as $segment) {
         if ($segment === null || is_array($node) === false || array_key_exists($segment, $node) === false) {
            return [false, null];
         }

         $node = $node[$segment];
      }

      // :
      return [true, $node];
   }
}
