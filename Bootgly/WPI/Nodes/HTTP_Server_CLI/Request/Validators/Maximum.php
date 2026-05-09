<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators;


use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function strlen;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Condition;


class Maximum extends Condition
{
   // * Config
   public private(set) int|float $limit;


   public function __construct (int|float $limit, string $message = '')
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
      if (is_int($value) || is_float($value)) {
         return $value <= $this->limit;
      }

      if (is_string($value)) {
         if (preg_match('/\A[-+]?(?:\d+\.?\d*|\.\d+)\z/', $value) === 1) {
            return (float) $value <= $this->limit;
         }

         return strlen($value) <= $this->limit;
      }

      if (is_array($value)) {
         return count($value) <= $this->limit;
      }

      return false;
   }

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} must be at most {$this->limit}.";
   }
}
