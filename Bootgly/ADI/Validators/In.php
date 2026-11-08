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


use function array_values;
use function in_array;
use function is_finite;
use function is_float;
use function is_scalar;

use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;


class In extends Condition implements Descriptor
{
   // * Config
   /**
    * @var array<int,mixed>
    */
   public private(set) array $values;
   public private(set) bool $strict;

   // * Metadata
   protected string $template = '{field} must be one of the allowed values.';


   /**
    * @param array<int,mixed> $values
    */
   public function __construct (array $values, bool $strict = true, string $message = '')
   {
      parent::__construct($message);

      // * Config
      $this->values = $values;
      $this->strict = $strict;
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return in_array($value, $this->values, $this->strict);
   }

   /**
    * JSON Schema: `enum` of the allowed values (compared strictly: a loose
    * `In` accepts more than the schema lists). `null` when a value is not
    * a JSON scalar.
    *
    * @return null|array<string,mixed>
    */
   public function describe (): null|array
   {
      foreach ($this->values as $value) {
         // ? Only JSON scalars
         if ($value !== null && is_scalar($value) === false) {
            return null;
         }
         if (is_float($value) && is_finite($value) === false) {
            return null;
         }
      }

      // :
      return ['enum' => array_values($this->values)];
   }
}
