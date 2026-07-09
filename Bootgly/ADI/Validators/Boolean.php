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


use function in_array;
use function is_bool;
use function is_int;
use function is_string;

use Bootgly\ADI\Validation\Condition;


class Boolean extends Condition
{
   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      if (is_bool($value)) {
         return true;
      }

      if (is_int($value)) {
         return $value === 0 || $value === 1;
      }

      if (is_string($value)) {
         return in_array($value, ['0', '1', 'true', 'false'], true);
      }

      return false;
   }

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} must be a boolean.";
   }
}
