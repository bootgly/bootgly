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
use function is_array;
use function is_string;

use Bootgly\ADI\Validation\Condition;


class MIME extends Condition
{
   // * Config
   /**
    * @var array<int,string>
    */
   public private(set) array $types;

   // * Metadata
   protected string $template = '{field} must have an allowed MIME type.';


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
}
