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


class __Array implements Data // Simple class (advanced methods coming soon)
{
   // * Data
   public array $array;


   public function __construct (array $array)
   {
      $this->array = &$array;
   }
   public function __get (string $property)
   {
      switch ($property) {
         // * Meta
         // @ Key
         case 'keys':
            return array_keys($this->array);
         // @ Value
         case 'values':
            return array_values($this->array);
         // @ Pointer
         // current
         case 'current':
            return current($this->array);
         case 'Current':
            return (object) [
               'key' => key($this->array),
               'value' => current($this->array)
            ];
         // next
         case 'next':
            return next($this->array);
         case 'Next':
            $nextValue = next($this->array);
            $nextKey = key($this->array);

            return (object) [
               'key' => $nextKey,
               'value' => $nextValue
            ];
         // previous
         case 'previous':
            return prev($this->array);
         case 'Previous':
            $previousValue = prev($this->array);
            $previousKey = key($this->array);

            return (object) [
               'key' => $previousKey,
               'value' => $previousValue
            ];
         // first
         case 'first':
            return reset($this->array);
         case 'First':
            $firstValue = reset($this->array);

            return (object) [
               'key' => key($this->array),
               'value' => $firstValue
            ];
         // last
         case 'last':
            end($this->array);
            return current($this->array);
         case 'Last':
            $lastValue = end($this->array);

            return (object) [
               'key' => key($this->array),
               'value' => $lastValue
            ];
         // @ Type
         case 'list':
            return array_is_list($this->array);
         case 'multidimensional':
            foreach ($this->array as $value) {
               if ( is_array($value) ) {
                  return true;
               }
            }
            return false;
      }
   }
   public function __call (string $name, array $arguments)
   {
      switch ($name) {
         // ->array
         case 'search':
            return self::search($this->array, ...$arguments);
      }
   }
   public static function __callStatic (string $name, array $arguments)
   {
      if ( method_exists(__CLASS__, $name) ) {
         return self::$name(...$arguments);
      }

      return null;
   }

   private static function search ($haystack, $needle, bool $strict = false) : object
   {
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

      return (object) [
         'key'   => $key,
         'value' => $value,
         'found' => $key !== false
      ];
   }
}
