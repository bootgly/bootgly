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


use function is_int;
use function is_string;
use function preg_match;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Condition;


class Integer extends Condition
{
   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return is_int($value) || (is_string($value) && preg_match('/\A[-+]?\d+\z/', $value) === 1);
   }

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} must be an integer.";
   }
}
