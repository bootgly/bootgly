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


use function is_int;
use function is_string;
use function preg_match;

use Bootgly\ADI\Validation\Condition;


class Integer extends Condition
{
   // * Metadata
   protected string $template = '{field} must be an integer.';


   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return is_int($value) || (is_string($value) && preg_match('/\A[-+]?\d+\z/', $value) === 1);
   }
}
