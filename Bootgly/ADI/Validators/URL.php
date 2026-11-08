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


use const FILTER_VALIDATE_URL;
use function filter_var;
use function is_string;

use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;


class URL extends Condition implements Descriptor
{
   // * Metadata
   protected string $template = '{field} must be a valid URL.';


   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
   }

   /**
    * JSON Schema: `type: string`, `format: uri`.
    *
    * @return null|array<string,mixed>
    */
   public function describe (): null|array
   {
      return ['type' => 'string', 'format' => 'uri'];
   }
}
