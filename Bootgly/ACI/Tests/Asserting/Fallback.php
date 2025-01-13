<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Asserting;


use Throwable;


class Fallback
{
   // * Config
   public string $format;
   /**
    * @var array<string,mixed>
    */
   public array $values;
   public int $verbosity;


   /**
    * Fallback constructor.
    *
    * @param string $format
    * @param array<string,mixed> $values
    * @param int $verbosity
    */
   public function __construct (
      string $format,
      array $values = [],
      int $verbosity = 0
   )
   {
      $this->format = $format;
      $this->values = $values;
      $this->verbosity = $verbosity;
   }

   /**
    * Translate values to output.
    *
    * @param array<string,mixed> $values
    *
    * @return array<string,float|int|string>
    */
   private function translate (array $values): array
   {
      $translated = [];

      foreach ($values as $key => $value) {
         $translated[$key] = match (gettype($value)) {
            'array' => 'array',
            'boolean' => $value ? 'true' : 'false',
            'NULL' => 'null',
            'object' => get_class($value),
            'resource' => 'resource',
            'resource (closed)' => 'resource (closed)',
            'unknown type' => 'unknown',
            default => $value // string, integer, double
         };
      }

      return $translated; // @phpstan-ignore-line
   }

   public function __toString (): string
   {
      $values = $this->translate($this->values);

      try {
         $message = vsprintf(
            format: $this->format,
            values: $values
         );
   
         return $message;
      }
      catch (Throwable) {
         // @ Set keys of values as values of values
         /**
          * ['A' => 'A'] => ['B' => 'B']
          */
         $values = array_combine(
            array_keys($this->values),
            array_keys($this->values)
         );

         // @ Prepend and append values
         $values = array_map(function($value) {
            return "\033[93m" . $value . "\033[0m";
         }, $values);

         $message = vsprintf(
            format: $this->format,
            values: $values
         );

         return $message;
      }
   }
}
