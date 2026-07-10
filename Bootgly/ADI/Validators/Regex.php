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


use function is_scalar;
use function preg_match;
use InvalidArgumentException;

use Bootgly\ADI\Validation\Condition;


class Regex extends Condition
{
   // * Config
   public private(set) string $pattern;

   // * Metadata
   protected string $template = '{field} has an invalid format.';


   public function __construct (string $pattern, string $message = '')
   {
      if (@preg_match($pattern, '') === false) {
         throw new InvalidArgumentException('Invalid validation regex pattern.');
      }

      parent::__construct($message);

      // * Config
      $this->pattern = $pattern;
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return is_scalar($value) && preg_match($this->pattern, (string) $value) === 1;
   }
}
