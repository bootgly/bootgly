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


use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Path;


class Confirmed extends Condition
{
   // * Config
   /**
    * The confirming field path from the source root (defaults to
    * `<field>_confirmation`, the sibling of a nested field).
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

      // ! The confirming value, read as a path (`user.password_confirmation`)
      [$exists, $confirmation] = new Path($confirming)->resolve($data);

      // :
      return $exists && $confirmation === $value;
   }
}
