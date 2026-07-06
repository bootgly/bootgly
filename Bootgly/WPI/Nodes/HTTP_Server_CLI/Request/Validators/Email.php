<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators;


use const FILTER_VALIDATE_EMAIL;
use function filter_var;
use function is_string;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Condition;


class Email extends Condition
{
   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
   }

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} must be a valid email address.";
   }
}
