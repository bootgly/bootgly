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


use function in_array;
use function is_array;
use function is_string;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Condition;


class MIME extends Condition
{
   // * Config
   /**
    * @var array<int,string>
    */
   public private(set) array $types;


   /**
    * @param string|array<int,string> $types
    */
   public function __construct (string|array $types, string $message = '')
   {
      parent::__construct($message);

      // * Config
      $this->types = is_array($types) ? $types : [$types];
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

      $type = $value['type'] ?? null;

      return is_string($type) && in_array($type, $this->types, true);
   }

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} must have an allowed MIME type.";
   }
}
