<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data;


use const PREG_SET_ORDER;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;
use const STR_PAD_RIGHT;
use function explode;
use function file_exists;
use function floor;
use function function_exists;
use function iconv_strlen;
use function implode;
use function mb_convert_case;
use function mb_str_split;
use function mb_strlen;
use function mb_strtolower;
use function mb_strtoupper;
use function mb_strwidth;
use function mb_substr;
use function method_exists;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function ucwords;

use Bootgly\ABI\Data;


class __String implements Data // Simple class (advanced methods coming soon)
{
   public const ANSI_ESCAPE_SEQUENCE_REGEX = '/\x1b\[[0-9;?]*[ -\/]*[@-~]/';

   // * Config
   public null|string $encoding;

   // * Data
   public string $string;

   // * Metadata
   public int|false $length {
      get {
         if ($this->encoding === 'ASCII') {
            $this->length = strlen($this->string);

            return $this->length;
         }

         if ( function_exists('mb_strlen') ) {
            $this->length = mb_strlen($this->string, $this->encoding);

            return $this->length;
         }

         $this->length = iconv_strlen($this->string, $this->encoding);

         return $this->length;
      }
   }
   // ! Case
   // False on error.
   public string|false $lowercase {
      get {
         if ($this->encoding === 'ASCII') {
            $this->lowercase = strtolower($this->string);

            return $this->lowercase;
         }

         if ( function_exists('mb_strtolower') ) {
            $this->lowercase = mb_strtolower($this->string, $this->encoding);

            return $this->lowercase;
         }

         // TODO polyfill?
         $this->lowercase = false;

         return $this->lowercase;
      }
   }
   public string|false $uppercase {
      get {
         if ($this->encoding === 'ASCII') {
            $this->uppercase = strtoupper($this->string);

            return $this->uppercase;
         }

         if ( function_exists('mb_strtoupper') ) {
            $this->uppercase = mb_strtoupper($this->string, $this->encoding);

            return $this->uppercase;
         }

         // TODO polyfill?
         $this->uppercase = false;

         return $this->uppercase;
      }
   }
   public string|false $pascalcase {
      get {
         if ($this->encoding === 'ASCII') {
            $this->pascalcase = ucwords($this->string);

            return $this->pascalcase;
         }

         if ( function_exists('mb_convert_case') ) {
            $this->pascalcase = mb_convert_case($this->string, 2, $this->encoding);

            return $this->pascalcase;
         }

         // TODO polyfill?
         $this->pascalcase = false;

         return $this->pascalcase;
      }
   }


   public function __construct (string $string, ?string $encoding = null)
   {
      // * Config
      $this->encoding = $encoding;

      // * Data
      $this->string = $string;

      // * Metadata
      // ...
   }
   /**
    * @param string $name
    * @param array<mixed> $arguments
    */
   public function __call (string $name, array $arguments): mixed
   {
      switch ($name) {
         case 'search':
            // !
            /** @var array<string>|string $search */
            $search = $arguments[0];
            /** @var int|null $offset */
            $offset = $arguments[1] ?? null;

            // :
            return self::$name(
               string: $this->string,
               search: $search,
               offset: $offset
            );
         case 'pad':
            // !
            /** @var int $length */
            $length = $arguments[0];
            /** @var string $padding */
            $padding = $arguments[1] ?? ' ';
            /** @var int $type */
            $type = $arguments[2] ?? STR_PAD_RIGHT;
            /** @var string $encoding */
            $encoding = $arguments[3] ?? $this->encoding ?? 'UTF-8';

            // :
            return self::$name(
               string: $this->string,
               length: $length,
               padding: $padding,
               type: $type,
               encoding: $encoding
            );
         case 'wrap':
            // !
            /** @var int $width */
            $width = $arguments[0];
            /** @var string $break */
            $break = $arguments[1] ?? "\n";
            /** @var bool $cut */
            $cut = $arguments[2] ?? false;
            /** @var string $encoding */
            $encoding = $arguments[3] ?? $this->encoding ?? 'UTF-8';

            // :
            return self::$name(
               string: $this->string,
               width: $width,
               break: $break,
               cut: $cut,
               encoding: $encoding
            );
         default:
            return null;
      }
   }
   /**
    * @param string $name
    * @param array<mixed> $arguments
    */
   public static function __callStatic (string $name, array $arguments): mixed
   {
      if ( method_exists(__CLASS__, $name) ) {
         return self::$name(...$arguments);
      }

      return null;
   }
   public function __toString (): string
   {
      return $this->string;
   }

   /**
    * Parse a string encoding to a certain encoding.
    * 
    * @param string|null $encoding
    * 
    * @return string
    *
    * @license MIT
    * @author Fabien Potencier and Symfony contributors
    * @link https://github.com/symfony/polyfill-mbstring
    */
   public static function encoding (string|null $encoding = null): string
   {
      if ($encoding === null) {
         $encoding = 'UTF-8';
      }

      $encoding = strtoupper($encoding);

      if ('8BIT' === $encoding || 'BINARY' === $encoding) {
         return 'CP850';
      }
      if ('UTF8' === $encoding) {
         return 'UTF-8';
      }

      return $encoding;
   }
   /**
    * Get data map from the unidata directory (used to polyfills, conversions, etc.).
    * 
    * @param string $case The case to get data from. (e.g. caseFolding, lowerCase, upperCase, titleCaseRegexp)
    *
    * @return array<string, string>|string|false
    */
   public static function mapping (string $case): array|string|false
   {
      if (file_exists($file = __DIR__.'/__String/resources/unidata/'.$case.'.php')) {
         return require $file;
      }

      return false;
   }

   /**
    * Search for a string in another string.
    * 
    * @param string $string
    * @param string|array<string> $search
    * @param int|null $offset
    * 
    * @return object
    */
   protected static function search (string $string, $search, ?int $offset = null): object
   {
      // !
      $terms = (array) $search;
      $position = false;
      $found = null;

      // @
      foreach ($terms as $term) {
         $position = strpos($string, $term, (int) $offset);

         if ($position !== false) {
            $found = $term;
            break;
         }
      }

      return (object) [
         'position' => $position,
         'found'    => $found
      ];
   }

   /**
    * Pad a string to a certain length with another string.
    * 
    * @param string $string
    * @param int $length
    * @param string $padding
    * @param int $type
    * @param string $encoding
    * 
    * @return string
    */
   protected static function pad (
      string $string,
      int $length,
      string $padding = ' ',
      int $type = STR_PAD_RIGHT,
      string $encoding = 'UTF-8'
   ): string
   {
      // Remove ANSI escape characters from the string when calculating length.
      $string_without_ansi = preg_replace(self::ANSI_ESCAPE_SEQUENCE_REGEX, '', $string);
      $input_length = mb_strlen((string) $string_without_ansi, $encoding);
      $pad_string_length = mb_strlen($padding, $encoding);

      if ($length <= 0 || ($length - $input_length) <= 0) {
         return $string;
      }

      $num_pad_chars = $length - $input_length;

      $left_pad = 0;
      $right_pad = 0;
      switch ($type) {
         case STR_PAD_RIGHT:
            $left_pad = 0;
            $right_pad = $num_pad_chars;
            break;

         case STR_PAD_LEFT:
            $left_pad = $num_pad_chars;
            $right_pad = 0;
            break;

         case STR_PAD_BOTH:
            $left_pad = floor($num_pad_chars / 2);
            $right_pad = $num_pad_chars - $left_pad;
            break;
      }

      $result = '';

      for ($i = 0; $i < $left_pad; ++$i) {
         $result .= mb_substr($padding, $i % $pad_string_length, 1, $encoding);
      }

      $result .= $string;

      for ($i = 0; $i < $right_pad; ++$i) {
         $result .= mb_substr($padding, $i % $pad_string_length, 1, $encoding);
      }

      return $result;
   }
   /**
    * Wraps a string to a given number of visible columns. ANSI escape
    * sequences are zero-width and multibyte characters count their display
    * width (CJK = 2). Open SGR styles are reset before an inserted break and
    * reopened after it, so per-line prefixes added later never bleed styles.
    * Pre-existing newlines are preserved; only inserted breaks use $break.
    *
    * @param string $string
    * @param int $width Visible columns per line (non-positive returns as-is).
    * @param string $break The break inserted between wrapped lines.
    * @param bool $cut Hard-split words wider than the width.
    * @param string $encoding
    *
    * @return string
    */
   protected static function wrap (
      string $string,
      int $width,
      string $break = "\n",
      bool $cut = false,
      string $encoding = 'UTF-8'
   ): string
   {
      // ? Non-positive widths cannot wrap
      if ($width <= 0) {
         return $string;
      }

      // @@ Wrap each pre-existing line independently
      $lines = explode("\n", $string);
      foreach ($lines as $index => $line) {
         $lines[$index] = self::fold($line, $width, $break, $cut, $encoding);
      }

      // :
      return implode("\n", $lines);
   }

   /**
    * Folds one line to the width — the wrap() engine.
    *
    * @param string $line
    * @param int $width
    * @param string $break
    * @param bool $cut
    * @param string $encoding
    *
    * @return string
    */
   private static function fold (
      string $line,
      int $width,
      string $break,
      bool $cut,
      string $encoding
   ): string
   {
      // ? Fast path — the visible width already fits
      $visible = (string) preg_replace(self::ANSI_ESCAPE_SEQUENCE_REGEX, '', $line);
      if (mb_strwidth($visible, $encoding) <= $width) {
         return $line;
      }

      // ! Atoms — ANSI escapes (zero-width) and single characters
      /** @var array<int,array{0:string,1:int}> $atoms */
      $atoms = [];
      $tokens = preg_split(
         '/(\x1b\[[0-9;?]*[ -\/]*[@-~])/',
         $line,
         -1,
         PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
      );

      foreach ($tokens === false ? [] : $tokens as $token) {
         // ? Escape sequences occupy no columns
         if ($token[0] === "\x1b") {
            $atoms[] = [$token, 0];

            continue;
         }

         foreach (mb_str_split($token, 1, $encoding) as $character) {
            $atoms[] = [$character, mb_strwidth($character, $encoding)];
         }
      }

      // ! Assembly state
      $output = '';
      $column = 0;   // Visible columns on the current output line
      $spaces = '';  // Pending inter-word gap
      $gap = 0;      // Visible width of the pending gap
      /** @var array<int,array{0:string,1:int}> $word */
      $word = [];    // Pending word atoms (escapes glue to the next character)
      $span = 0;     // Visible width of the pending word
      /** @var array<int,string> $open */
      $open = [];    // SGR sequences open in the emitted stream

      // @ Emit a chunk, tracking its SGR state (a reset clears, styles stack)
      $emit = static function (string $chunk) use (&$output, &$open): void {
         $output .= $chunk;

         preg_match_all('/\x1b\[([0-9;]*)m/', $chunk, $matches, PREG_SET_ORDER);
         foreach ($matches as $match) {
            if ($match[1] === '' || $match[1] === '0') {
               $open = [];
            }
            else {
               $open[] = $match[0];
            }
         }
      };
      // @ Break the line — reset open styles, break, reopen them
      $split = static function () use (&$output, &$column, &$open, $break): void {
         if ($open !== []) {
            $output .= "\e[0m";
         }

         $output .= $break;

         foreach ($open as $sequence) {
            $output .= $sequence;
         }

         $column = 0;
      };

      // ! Sentinel boundary — flushes the last word
      $atoms[] = [' ', 1];

      // @@ Greedy fill — gaps at inserted breaks are dropped
      foreach ($atoms as [$content, $columns]) {
         // # Word boundary
         if ($content === ' ' && $columns === 1) {
            // ? Flush the pending word
            if ($word !== []) {
               $fits = $column + $gap + $span <= $width;

               if ($column > 0 && $fits === false) {
                  $split();
               }
               else if ($gap > 0) {
                  $emit($spaces);
                  $column += $gap;
               }

               // @@ Place the word — hard-cut only when allowed
               foreach ($word as [$piece, $size]) {
                  if ($cut === true && $size > 0 && $column > 0 && $column + $size > $width) {
                     $split();
                  }

                  $emit($piece);
                  $column += $size;
               }

               $spaces = '';
               $gap = 0;
               $word = [];
               $span = 0;
            }

            $spaces .= $content;
            $gap += $columns;

            continue;
         }

         $word[] = [$content, $columns];
         $span += $columns;
      }

      // :
      return $output;
   }
}
