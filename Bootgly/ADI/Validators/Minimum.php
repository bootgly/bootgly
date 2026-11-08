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


use function ceil;
use function count;
use function is_array;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function strlen;

use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;


class Minimum extends Condition implements Descriptor
{
   // * Config
   public private(set) int|float $limit;

   // * Metadata
   protected string $template = '{field} must be at least {limit}.';


   public function __construct (int|float $limit, string $message = '')
   {
      parent::__construct($message);

      // * Config
      $this->limit = $limit;

      // * Metadata
      $this->substitutions = ['limit' => (string) $limit];
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      if (is_int($value) || is_float($value)) {
         return $value >= $this->limit;
      }

      if (is_string($value)) {
         if (preg_match('/\A[-+]?(?:\d+\.?\d*|\.\d+)\z/', $value) === 1) {
            return (float) $value >= $this->limit;
         }

         return strlen($value) >= $this->limit;
      }

      if (is_array($value)) {
         return count($value) >= $this->limit;
      }

      return false;
   }

   /**
    * JSON Schema: `minimum` for numbers, and `minLength` / `minItems` /
    * `minProperties` for strings, arrays and objects (the Renderer keeps the
    * one the value's `type` needs). String lengths here count bytes, JSON
    * Schema counts characters; numeric strings compare as numbers here.
    * `null` for an infinite limit.
    *
    * @return null|array<string,mixed>
    */
   public function describe (): null|array
   {
      // ? JSON has no infinity
      if (is_finite((float) $this->limit) === false) {
         return null;
      }

      $keywords = ['minimum' => $this->limit];

      // ? Lengths and counts: the least whole count the limit admits
      if ($this->limit > 0 && $this->limit < 9.0E18) {
         $count = (int) ceil($this->limit);
         $keywords += ['minLength' => $count, 'minItems' => $count, 'minProperties' => $count];
      }

      // :
      return $keywords;
   }
}
