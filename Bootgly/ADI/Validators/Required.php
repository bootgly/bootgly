<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Validators;


use function is_array;
use function is_string;
use function trim;

use Bootgly\ADI\Validation\Condition;


class Required extends Condition
{
   public function __construct (string $message = '')
   {
      parent::__construct($message);

      // * Config
      $this->implicit = true;
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      if ($value === null) {
         return false;
      }

      if (is_string($value)) {
         return trim($value) !== '';
      }

      if (is_array($value)) {
         return $value !== [];
      }

      return true;
   }

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} is required.";
   }
}
