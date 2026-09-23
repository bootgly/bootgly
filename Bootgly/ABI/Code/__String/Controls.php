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


use function chr;
use function preg_replace_callback;
use function range;
use function sprintf;
use function str_contains;
use function strtr;


/**
 * Control characters — the canonical way to put text a program did not author on a terminal.
 */
class Controls
{
   // A complete 7-bit SGR sequence, or one C0/DEL byte, or one C1 code point in UTF-8.
   private const string CONTROLS = '/\e\[[0-9;]*m|[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/';
   // The short escapes `json_encode()` uses.
   private const array SHORT = [
      "\x08" => '\b',
      "\t"   => '\t',
      "\n"   => '\n',
      "\x0C" => '\f',
      "\r"   => '\r',
   ];

   // * Metadata
   /**
    * One escape map per `$keep` set: control character → its visible escape.
    * @var array<string,array<string,string>>
    */
   private static array $maps = [];


   /**
    * Escape every control character in a text, visibly, so it cannot drive a UTF-8 terminal.
    *
    * C0 (U+0000–U+001F), DEL (U+007F) and C1 (U+0080–U+009F) become the escape `json_encode()`
    * writes for them — `\b` `\t` `\n` `\f` `\r`, else `\u00xx` — so an OSC, DCS, APC or CSI
    * sequence loses the byte that introduces it and shows up as plain text instead. The text is
    * never shortened (nothing that follows a control is swallowed), the escapes contain no `@`
    * (so they never form template markup), escaping twice changes nothing, and escaping the output
    * of `json_encode()` keeps the JSON valid and lossless: decoding restores the character.
    *
    * The text is read as bytes, so invalid UTF-8 never fails: a C1 code point is its UTF-8 form
    * (`C2 80`–`C2 9F`), while a stray byte 0x80–0x9F outside UTF-8 is left as it is — a terminal
    * that is not in UTF-8 mode may read it as a C1 control, so pass text that may not be UTF-8
    * through `mb_scrub()` first.
    *
    * @param string $text The text to escape.
    * @param string $keep The C0 characters to leave as they are (e.g. "\t\n" for multi-line text).
    * @param bool $SGR Leave complete SGR sequences (`ESC [ 0-9 ; m`, colours and styles) as they are.
    *
    * @return string The escaped text.
    */
   public static function escape (string $text, string $keep = '', bool $SGR = false): string
   {
      $map = self::$maps[$keep] ??= self::compile($keep);

      // ?: No SGR to keep — one `strtr()` pass (no per-character callback: a large text full of
      //    controls costs what a plain copy costs)
      if ($SGR === false) {
         return strtr($text, $map);
      }

      // : A complete SGR is not in the map, so it stays whole; every other control is mapped
      return preg_replace_callback(
         self::CONTROLS,
         static fn (array $matches): string => $map[$matches[0]] ?? $matches[0],
         $text
      ) ?? ''; // ? A PCRE failure (not expected for this pattern) fails closed
   }

   /**
    * Build the escape map for one `$keep` set.
    *
    * @return array<string,string>
    */
   private static function compile (string $keep): array
   {
      $map = [];

      // @@ C0 and DEL — the ones the caller keeps stay out of the map
      foreach ([...range(0x00, 0x1F), 0x7F] as $code) {
         $control = chr($code);
         if ($keep !== '' && str_contains($keep, $control)) {
            continue;
         }

         $map[$control] = self::SHORT[$control] ?? sprintf('\u%04x', $code);
      }
      // @@ C1 — its UTF-8 form
      foreach (range(0x80, 0x9F) as $code) {
         $map["\xC2" . chr($code)] = sprintf('\u%04x', $code);
      }

      // :
      return $map;
   }
}
