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
   public ? string $encoding;

   // * Data
   public string $string;

   // * Metadata
   private int|false $length;
   // ! Case
   // False on error.
   private string|false $lowercase;
   private string|false $uppercase;
   private string|false $pascalcase;


   public function __construct (string $string, ? string $encoding = null)
   {
      // * Config
      $this->encoding = $encoding;

      // * Data
      $this->string = $string;

      // * Metadata
      // ...
   }
   public function __get (string $index)
   {
      switch ($index) {
         // * Metadata
         case 'length':
            if ($this->encoding === 'ASCII') {
               return $this->length = strlen($this->string);
            }

            if ( function_exists('mb_strlen') ) {
               return $this->length = mb_strlen($this->string, $this->encoding);
            }

            return $this->length = iconv_strlen($this->string, $this->encoding);
         // ! Case
         case 'lowercase':
            if ($this->encoding === 'ASCII') {
               return $this->lowercase = strtolower($this->string);
            }

            if ( function_exists('mb_strtolower') ) {
               return $this->lowercase = mb_strtolower($this->string, $this->encoding);
            }

            // TODO polyfill?
            return $this->lowercase = false;
         case 'uppercase':
            if ($this->encoding === 'ASCII') {
               return $this->uppercase = strtoupper($this->string);
            }

            if ( function_exists('mb_strtoupper') ) {
               return $this->uppercase = mb_strtoupper($this->string, $this->encoding);
            }

            // TODO polyfill?
            return $this->uppercase = false;
         case 'pascalcase':
            if ($this->encoding === 'ASCII') {
               return $this->pascalcase = ucwords($this->string);
            }

            if ( function_exists('mb_convert_case') ) {
               return $this->pascalcase = mb_convert_case($this->string, \MB_CASE_TITLE, $this->encoding);
            }

            // TODO polyfill?
            return $this->pascalcase = false;
         default:
            return null;
      }
   }
   public function __call (string $name, $arguments)
   {
      switch ($name) {
         case 'search':
            return self::search($this->string, ...$arguments);
         case 'pad':
            return self::pad(
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
   public static function __callStatic (string $name, $arguments)
   {
      if ( method_exists(__CLASS__, $name) ) {
         return self::$name(...$arguments);
      }

      return null;
   }
   public function __toString () : string
   {
      return $this->string;
   }

   private static function search (string $string, $search, int $offset = null) : object
   {
      $terms = (array) $search;
      $found = null;

      foreach ($terms as $term) {
         $position = strpos($string, $term, $offset);

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

   private static function pad (
      string $string,
      int $length,
      string $padding = ' ',
      int $type = STR_PAD_RIGHT,
      string $encoding = 'UTF-8'
   ) {
      // Remove ANSI escape characters from the string when calculating length.
      $string_without_ansi = preg_replace(self::ANSI_ESCAPE_SEQUENCE_REGEX, '', $string);
      $input_length = mb_strlen($string_without_ansi, $encoding);
      $pad_string_length = mb_strlen($padding, $encoding);

      if ($length <= 0 || ($length - $input_length) <= 0) {
         return $string;
      }

      $num_pad_chars = $length - $input_length;

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
