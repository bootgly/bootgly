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


use function is_scalar;
use function preg_match;
use InvalidArgumentException;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Condition;


class Regex extends Condition
{
   // * Config
   public private(set) string $pattern;


   public function __construct (string $pattern, string $message = '')
   {
      if (@preg_match($pattern, '') === false) {
         throw new InvalidArgumentException('Invalid validation regex pattern.');
      }

      parent::__construct($message);

      // * Config
      $this->pattern = $pattern;
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return is_scalar($value) && preg_match($this->pattern, (string) $value) === 1;
   }

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} has an invalid format.";
   }
}
