<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data;


use Bootgly\ABI\Data;


class __String implements Data // Simple class (advanced methods coming soon)
{
   public const ANSI_ESCAPE_SEQUENCE_REGEX = '/\x1B\[[0-9;]*[mK]/';

   // * Config
   public ?string $encoding;

   // * Data
   public string $string;

   // * Metadata
   private int|false $length;
   // ! Case
   // False on error.
   private string|false $lowercase;
   private string|false $uppercase;
   private string|false $pascalcase;


   public function __construct (string $string, ?string $encoding = null)
   {
      // * Config
      $this->encoding = $encoding;

      // * Data
      $this->string = $string;

      // * Metadata
      // ...
   }
   public function __get (string $index): mixed
   {
      switch ($index) {
         // * Metadata
         case 'length':
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
         // ! Case
         case 'lowercase':
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
         case 'uppercase':
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
         case 'pascalcase':
            if ($this->encoding === 'ASCII') {
               $this->pascalcase = ucwords($this->string);
               return $this->pascalcase;
            }

            if ( function_exists('mb_convert_case') ) {
               $this->pascalcase = mb_convert_case($this->string, \MB_CASE_TITLE, $this->encoding);
               return $this->pascalcase;
            }

            // TODO polyfill?
            $this->pascalcase = false;
            return $this->pascalcase;
         default:
            return null;
      }
   }
   /**
    * @param string $name
    * @param array<mixed> $arguments
    */
   public function __call (string $name, array $arguments): mixed
   {
      switch ($name) {
         case 'search':
            return self::$name(
               string: $this->string,
               search: $arguments[0],
               offset: $arguments[1] ?? 0
            );
         case 'pad':
            return self::$name(
               string: $this->string,
               length: $arguments[0],
               padding: $arguments[1] ?? ' ',
               type: $arguments[2] ?? 1,
               encoding: $arguments[3] ?? $this->encoding ?? 'UTF-8'
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
    * @return array<string, string>|false
    */
   public static function mapping (string $case): array|false
   {
      if (file_exists($file = __DIR__.'/resources/unidata/'.$case.'.php')) {
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
}
