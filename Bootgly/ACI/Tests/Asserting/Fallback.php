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

   public function __toString (): string
   {
      try {
         $message = vsprintf(
            format: $this->format,
            values: $this->values
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
