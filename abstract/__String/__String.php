<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


#[\AllowDynamicProperties]
final class __String // TODO refactor old class
{
   public string $string;

   // * Config
   // public $insensitive;
   // ! Character Encoding

   // * Data
   public $pattern;
   public $replacement;
   public $limit;
   public $count; // size?, length?

   // * Meta
   // int $length
   // ! Case
   // string $lowercase
   // string $uppercase
   // string $pascalcase
   // ! Call ?
   private string $called;
   private array $arguments;


   public function __construct (string $string)
   {
      $this->string = $string;

      // * Meta
      $this->called = '';
      $this->arguments = [];
   }
   public function __get (string $index)
   {
      switch ($index) {
            // * Meta
         case 'length':
            return strlen($this->string); // TODO check encoding before get length
            // ! Case
         case 'lowercase':
            return strtolower($this->string);
         case 'uppercase':
            return strtoupper($this->string);
         case 'pascalcase':
            return ucwords($this->string);
            // ! Call ?
            // ? cut()
         case 'cutted':
            return $this->cutted;
         case 'rest': // TODO Rest of string cutted
            if ($this->called === 'cut' && @$this->arguments[0] === 0) {
               return substr($this->string, strlen($this->cutted));
            }
      }
   }
   public function __call (string $name, $arguments)
   {
      $this->called = $name;
      $this->arguments = $arguments;

      switch ($name) {
         case 'replace':
            $search = @$arguments[0];
            $replace = @$arguments[1];
            return self::replace($search, $replace, @$this->string);

         case 'cut':
            return $this->cutted = self::cut($this->string, ...$arguments);

         case 'separate':
            return self::separate($this->string, ...$arguments);
         case 'separateBefore':
            return self::separate($this->string, 'before', ...$arguments);
         case 'separateAfter':
            return self::separate($this->string, 'after', ...$arguments);

         case 'explode':
            $delimiter = @$arguments[0];
            $limit = @$arguments[1];
            return self::explode($delimiter, @$this->string, $limit);
      }
   }
   public static function __callStatic (string $name, $arguments)
   {
      return self::$name(...$arguments);
   }
   public function __toString ()
   {
      return $this->string;
   }

   // TODO Refactor this function to reduce its Cognitive Complexity from 32 to the 15 allowed.
   private static function cut (string $str, ...$arguments)
   {
      if (is_int(@$arguments[0])) {
         $start = $arguments[0];
         if (is_int(@$arguments[1])) {
            $length = $arguments[1];

            return substr($str, $start, $length);
         } else if (is_string(@$arguments[1])) {
            $at = $arguments[1];
            $length = strpos($str, $at);

            return substr($str, $start, $length);
         }
      } else if (is_string($arguments[0])) {
         $needle = @$arguments[0];
         $position = @$arguments[1];
         $caseSensitive = $arguments[2] ?? true;

         switch ($position) {
               //! strpos
            case '^': // Remove $needle if found from the beginning of $str and return the rest
               $strPosFunction = $caseSensitive ? "strpos" : "stripos";

               if ($strPosFunction($str, $needle) === 0) {
                  $str = substr($str, strlen($needle));
               }

               return $str;
            case '$': // Remove $needle if found from the end of $str and return the rest
               $strPosFunction = $caseSensitive ? "strpos" : "stripos";

               if ($strPosFunction($str, $needle, strlen($str) - strlen($needle)) !== false) {
                  $str = substr($str, 0, -strlen($needle));
               }

               return $str;

               //! strstr
            case '?|': // (?) Find $needle (|) cut beetween $needle (:) return all before $needle
               $strstrFunction = $caseSensitive ? "strstr" : "stristr";

               return $strstrFunction($str, $needle, true);
            case '|?': // Remove portion of a string before a certain character and return the rest
               $strstrFunction = $caseSensitive ? "strstr" : "stristr";

               return $strstrFunction($str, $needle, false);
         }
      }
   }
   private static function separate (string $str, string $part, string $needle, bool $caseSensitive = true)
   {
      //@ Search direction = left-to-right
      //@ Limit count = 1 (first occurrence)
      switch ($part) {
         case 'before':
            $strstrFunction = $caseSensitive ? "strstr" : "stristr";
            return $strstrFunction($str, $needle, true);

         case 'after':
            $strstrFunction = $caseSensitive ? "strstr" : "stristr";
            return substr($strstrFunction($str, $needle, false), strlen($needle));

         case 'around':
         case 'between':
      }
   }
   // Strip (remove) whitespace (or other characters)
   public static function trim (string $str, string $characters = " \t\n\r\0\x0B", int $direction = 0): string
   {
      $result = '';
      if ($direction === 0) { // beginning and end of a string
         $result = trim($str, $characters);
      } elseif ($direction === 1) { // end of a string
         $result = rtrim($str, $characters);
      } elseif ($direction === -1) { // beginning of a string
         $result = ltrim($str, $characters);
      }

      return $result;
   }
   public static function search (string $haystack, $needle, int $offset = null)
   {
      $needles = (array)$needle;
      $found = null;

      foreach ($needles as $needle) {
         $position = strpos($haystack, $needle, $offset);
         if ($position !== false) {
            $found = $needle;
            break;
         }
      }

      return new class ($position, $found)
      {
         public $position;
         public $found;

         public function __construct ($position, $found)
         {
            $this->position = $position;
            $this->found = $found;
         }
      };
   }
   private static function replace ($search, $replace, $subject)
   {
      return str_replace($search, $replace, $subject);
   }
   private static function explode (string $delimiter, string $string, ?int $limit)
   {
      return explode($delimiter, $string, $limit);
   }

   public static function pad ($input, $length, $padding = ' ', $type = STR_PAD_RIGHT, $encoding = 'UTF-8')
   {
      $inputLength = mb_strlen($input, $encoding);
      $padStringLength = mb_strlen($padding, $encoding);

      if ($length <= 0 || ($length - $inputLength) <= 0) {
         return $input;
      }

      $numPadChars = $length - $inputLength;

      switch ($type) {
         case STR_PAD_RIGHT:
            $leftPad = 0;
            $rigthPad = $numPadChars;
            break;

         case STR_PAD_LEFT:
            $leftPad = $numPadChars;
            $rigthPad = 0;
            break;

         case STR_PAD_BOTH:
            $leftPad = floor($numPadChars / 2);
            $rigthPad = $numPadChars - $leftPad;
            break;
      }

      $result = '';
      for ($i = 0; $i < $leftPad; ++$i) {
         $result .= mb_substr($padding, $i % $padStringLength, 1, $encoding);
      }

      $result .= $input;
      for ($i = 0; $i < $rigthPad; ++$i) {
         $result .= mb_substr($padding, $i % $padStringLength, 1, $encoding);
      }

      return $result;
   }
}
