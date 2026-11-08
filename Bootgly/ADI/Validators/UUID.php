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
use function preg_match;

use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;


/**
 * A UUID in its canonical text form: 32 hexadecimal digits in groups of
 * 8-4-4-4-12, either case (RFC 9562). Any version and variant pass —
 * including the nil and max UUIDs; braces, `urn:uuid:` and unhyphenated
 * forms fail.
 */
class UUID extends Condition implements Descriptor
{
   // * Metadata
   protected string $template = '{field} must be a valid UUID.';


   /**
    * Pass when the value is a canonical UUID string.
    *
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return is_string($value)
         && preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $value) === 1;
   }

   /**
    * JSON Schema: `type: string`, `format: uuid`.
    *
    * @return null|array<string,mixed>
    */
   public function describe (): null|array
   {
      return ['type' => 'string', 'format' => 'uuid'];
   }
}
