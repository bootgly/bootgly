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


use function array_key_exists;

use Bootgly\ADI\Validation\Condition;


class Confirmed extends Condition
{
   // * Config
   /**
    * The confirming field name (defaults to `<field>_confirmation`).
    */
   public private(set) null|string $field;

   // * Metadata
   protected string $template = '{field} confirmation does not match.';


   public function __construct (null|string $field = null, string $message = '')
   {
      parent::__construct($message);

      // * Config
      $this->field = $field;
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      $confirming = $this->field ?? "{$field}_confirmation";

      return array_key_exists($confirming, $data) && $data[$confirming] === $value;
   }
}
