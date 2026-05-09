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


use function is_array;
use function is_string;
use function trim;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Condition;


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
