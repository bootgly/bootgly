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

use Bootgly\ADI\Validation\Condition;


class In extends Condition
{
   // * Config
   /**
    * @var array<int,mixed>
    */
   public private(set) array $values;
   public private(set) bool $strict;


   /**
    * @param array<int,mixed> $values
    */
   public function __construct (array $values, bool $strict = true, string $message = '')
   {
      parent::__construct($message);

      // * Config
      $this->values = $values;
      $this->strict = $strict;
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return in_array($value, $this->values, $this->strict);
   }

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} must be one of the allowed values.";
   }
}
