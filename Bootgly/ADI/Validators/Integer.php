<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Validators;


use function is_int;
use function is_string;
use function preg_match;

use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;


class Integer extends Condition implements Descriptor
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

   /**
    * JSON Schema: `type: integer` (integer strings such as `"18"` are accepted here too).
    *
    * @return null|array<string,mixed>
    */
   public function describe (): null|array
   {
      return ['type' => 'integer'];
   }
}
