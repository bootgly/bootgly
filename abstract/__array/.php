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


// TODO refactor
class __Array
{
   public array $array;


   public function __construct (array $array)
   {
      $this->array = $array;
   }
   public function __get (string $property)
   {
      switch ($property) {
            // * Config

            // * Data
            // ! Array Key
         case 'keys':
            return array_keys($this->array);
            // ! Array Value
         case 'values':
            return array_values($this->array);

            // * Meta
            // ! Array Pointer
            // ? Current
         case 'Current': // TODO return Current->key, Current->value;
            break;
         case 'current':
            return current($this->array);
            // ? Next
         case 'Next': // TODO return Next->key, Next->value;
            break;
         case 'next': // By default return the next Array->Last->value;
            return next($this->array);
            // ? Previous
         case 'Previous': // TODO return Previous->key, Previous->value;
            break;
         case 'previous':
            return prev($this->array);
            // ? First || Start || Reset() ??
         case 'First': // TODO
            break;
         case 'first': // TODO
            break;
            // ? Last || End
         case 'Last':
            return new class($this->array)
            {
               private array $array;

               public function __construct(array $array)
               {
                  $this->array = $array;
               }

               public function __get(string $property)
               {
                  switch ($property) {
                     case 'Key':
                        // TODO implement ->Key->number (indexed),
                        // TODO ->Key->string (associative)
                        // TODO ->Key->array (multidimensional)
                     case 'key':
                        $array = $this->array;
                        end($array);
                        return key($array);

                     case 'value':
                        $array = $this->array;
                        return end($array);
                  }
               }
            };
         case 'last': // TODO by default return last key or last value???
            break;
            // ! Array Type
         case 'type': // TODO return 'indexed or numeric', 'associative', 'multidimensional', 'mixed??'
            break;
      }
   }
   public function __call ($name, $arguments)
   {
      switch ($name) {
         case 'search':
            return self::search($this->array, ...$arguments);
      }
   }
   public static function __callStatic (string $name, $arguments)
   {
      return self::$name(...$arguments);
   }

   public static function check ($payload)
   { //!?!
      return is_array($payload);
   }
   private static function search ($haystack, $needle, bool $strict = false)
   { // TODO pattern
      $haystack = (array) $haystack;
      $needles = (array) $needle;

      $key = false;
      $value = false;

      foreach ($needles as $needle) {
         $key = array_search($needle, $haystack, $strict);

         if ($key !== false) {
            if (@$haystack[$key] === $needle) {
               $value = $haystack[$key];
            }
            break;
         }
      }

      return new class ($key, $value)
      {
         public $key;
         public $value;
         public bool $found;

         public function __construct ($key, $value)
         {
            $this->key = $key;
            $this->value = $value;

            $this->found = $key !== false;
         }
      };
   }
   public static function merge (array $array1, array ...$arrays)
   {
      return array_merge($array1, ...$arrays);
   }
   public static function sort (array &$array, ...$options) : bool
   {
      if (is_int(@$options[0])) {
         return sort($array, @$options[0]);
      } else {
         return usort($array, @$options[0]);
      }
   }
}
