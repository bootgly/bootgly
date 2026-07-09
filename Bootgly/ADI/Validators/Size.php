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
use function is_int;

use Bootgly\ADI\Validation\Condition;


class Size extends Condition
{
   // * Config
   public private(set) int $limit;


   public function __construct (int $limit, string $message = '')
   {
      parent::__construct($message);

      // * Config
      $this->limit = $limit;
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      if (is_array($value) === false) {
         return false;
      }

      if (($value['error'] ?? null) !== 0) {
         return false;
      }

      $size = $value['size'] ?? null;

      return is_int($size) && $size <= $this->limit;
   }

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} must be at most {$this->limit} bytes.";
   }
}
