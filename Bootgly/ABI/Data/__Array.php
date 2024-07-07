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
   /** @var array<mixed> */
   public array $array;
   // * Metadata
   /** @var array<int|string> */
   private array $keys;
   /** @var array<mixed> */
   private array $values;
   private mixed $current;
   private object $Current;
   private mixed $next;
   private object $Next;
   private mixed $previous;
   private object $Previous;
   private mixed $first;
   private object $First;
   private mixed $last;
   private object $Last;
   private bool $list;
   private bool $multidimensional;


   /**
    * @param array<mixed> $array
    */
   public function __construct (array $array)
   {
      $this->array = &$array;
   }
   public function __get (string $property): mixed
   {
      switch ($property) {
         // * Metadata
         // @ Key
         case 'keys':
            $this->keys = array_keys($this->array);
            return $this->keys;
         // @ Value
         case 'values':
            $this->values = array_values($this->array);
            return $this->values;
         // @ Pointer
         // current
         case 'current':
            $this->current = current($this->array);
            return $this->current;
         case 'Current':
            $this->Current = (object) [
               'key' => key($this->array),
               'value' => current($this->array)
            ];
            return $this->Current;
         // next
         case 'next':
            $this->next = next($this->array);
            return $this->next;
         case 'Next':
            $nextValue = next($this->array);
            $nextKey = key($this->array);

            $this->Next = (object) [
               'key' => $nextKey,
               'value' => $nextValue
            ];
            return $this->Next;
         // previous
         case 'previous':
            $this->previous = prev($this->array);
            return $this->previous;
         case 'Previous':
            $previousValue = prev($this->array);
            $previousKey = key($this->array);

            $this->Previous = (object) [
               'key' => $previousKey,
               'value' => $previousValue
            ];
            return $this->Previous;
         // first
         case 'first':
            $this->first = reset($this->array);
            return $this->first;
         case 'First':
            $firstValue = reset($this->array);

            $this->First = (object) [
               'key' => key($this->array),
               'value' => $firstValue
            ];
            return $this->First;
         // last
         case 'last':
            end($this->array);
            $this->last = current($this->array);
            return $this->last;
         case 'Last':
            $lastValue = end($this->array);

            $this->Last = (object) [
               'key' => key($this->array),
               'value' => $lastValue
            ];
            return $this->Last;
         // @ Type
         case 'list':
            $this->list = array_is_list($this->array);
            return $this->list;
         case 'multidimensional':
            $multidimensional = false;
            foreach ($this->array as $value) {
               if ( is_array($value) ) {
                  $multidimensional = true;
                  break;
               }
            }
            $this->multidimensional = $multidimensional;
            return $this->multidimensional;
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
         // ->array
         case 'search':
            return self::search($this->array, ...$arguments);
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

   /**
    * Search for a value in an array.
    * 
    * @param array<mixed> $haystack
    * @param mixed $needle
    * @param bool $strict
    * 
    * @return object
    */
   private static function search ($haystack, $needle, bool $strict = false): object
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
