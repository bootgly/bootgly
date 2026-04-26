<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Grammar;


use function array_keys;
use function chr;
use function mt_rand;
use function shuffle;
use function str_repeat;
use function strlen;
use function strtolower;
use function strtoupper;


/**
 * Headers — generators for header *name* and *value* mutations.
 *
 * Used by the fuzz specs to produce variants whose framing semantics MUST
 * remain invariant (case-insensitive lookup, OWS tolerance per RFC 9110,
 * permutation-independent dispatch).
 */
class Headers
{
   /**
    * Randomly mixed-case the canonical header name.
    *
    * Examples for "Content-Length":
    *   content-length, CONTENT-LENGTH, Content-LENGTH, cOntEnt-LeNgth, ...
    */
   public static function mix (string $name): string
   {
      $out = '';
      $len = strlen($name);
      for ($i = 0; $i < $len; $i++) {
         $c = $name[$i];
         if ($c === '-') {
            $out .= '-';
            continue;
         }
         $out .= mt_rand(0, 1) === 1 ? strtoupper($c) : strtolower($c);
      }
      return $out;
   }

   /**
    * Insert RFC 9110 OWS (optional whitespace) around the colon — servers
    * MUST accept zero-or-more SP/HTAB on either side per the field-value
    * grammar.
    *
    * @return string e.g. " Host:  example.com "
    */
   public static function pad (string $name, string $value): string
   {
      $leftOws  = str_repeat(mt_rand(0, 1) ? ' ' : "\t", mt_rand(0, 2));
      $rightOws = str_repeat(mt_rand(0, 1) ? ' ' : "\t", mt_rand(0, 3));
      return $name . ':' . $leftOws . $value . $rightOws;
   }

   /**
    * Permute an associative {name => value} header set.
    *
    * @param array<string,string> $headers
    * @return array<int,string> raw "Name: Value" lines (no CRLF)
    */
   public static function permute (array $headers, bool $mix = true, bool $pad = true): array
   {
      $keys = array_keys($headers);
      shuffle($keys);

      $lines = [];
      foreach ($keys as $name) {
         $finalName = $mix ? self::mix($name) : $name;
         $value = $headers[$name];
         $lines[] = $pad
            ? self::pad($finalName, $value)
            : "$finalName: $value";
      }
      return $lines;
   }

   /**
    * Generate a random ASCII token of safe header-value bytes (no CR/LF).
    *
    * @internal Used to seed noisy headers; never produces control chars.
    */
   public static function mint (int $minLen = 4, int $maxLen = 16): string
   {
      $len = mt_rand($minLen, $maxLen);
      $out = '';
      for ($i = 0; $i < $len; $i++) {
         // @ Printable ASCII excluding CR/LF/colon/space
         $byte = mt_rand(0x21, 0x7E);
         if ($byte === 0x3A) $byte = 0x2D; // ':' -> '-'
         $out .= chr($byte);
      }
      return $out;
   }
}
