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



use function is_string;

use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;


/**
 * A string — any string, `''` included when the rule runs on it. Numbers,
 * booleans and arrays fail: it declares the `string` type a field holds.
 */
class Text extends Condition implements Descriptor
{
   // * Metadata
   protected string $template = '{field} must be a string.';


   /**
    * Pass when the value is a string.
    *
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return is_string($value);
   }

   /**
    * JSON Schema: `type: string`.
    *
    * @return null|array<string,mixed>
    */
   public function describe (): null|array
   {
      return ['type' => 'string'];
   }
}
