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


use function is_array;
use function is_string;
use function trim;

use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;


class Required extends Condition implements Descriptor
{
   // * Metadata
   protected string $template = '{field} is required.';


   public function __construct (string $message = '')
   {
      parent::__construct($message);

      // * Config
      $this->implicit = true;
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      if ($value === null) {
         return false;
      }

      if (is_string($value)) {
         return trim($value) !== '';
      }

      if (is_array($value)) {
         return $value !== [];
      }

      return true;
   }

   /**
    * JSON Schema: required in the parent and non-blank (the `required` marker of `Descriptor`).
    *
    * @return null|array<string,mixed>
    */
   public function describe (): null|array
   {
      return ['required' => true];
   }
}
