<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation;


abstract class Condition
{
   // * Config
   public protected(set) bool $implicit = false;
   public protected(set) string $message;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (string $message = '')
   {
      // * Config
      $this->message = $message;
   }

   /**
    * @param array<string,mixed> $data
    */
   abstract public function validate (string $field, mixed $value, array $data): bool;

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} is invalid.";
   }
}
