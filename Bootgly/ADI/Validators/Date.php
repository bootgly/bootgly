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


use function is_string;
use function strtotime;
use DateTimeImmutable;

use Bootgly\ADI\Validation\Condition;


class Date extends Condition
{
   // * Config
   /**
    * A `DateTimeImmutable::createFromFormat()` format (null = any `strtotime()` parseable date).
    */
   public private(set) null|string $format;

   // * Metadata
   protected string $template = '{field} must be a valid date.';


   public function __construct (null|string $format = null, string $message = '')
   {
      parent::__construct($message);

      // * Config
      $this->format = $format;

      // * Metadata
      if ($format !== null) {
         $this->template = '{field} must be a valid date in the format {format}.';
         $this->substitutions = ['format' => $format];
      }
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      if (is_string($value) === false || $value === '') {
         return false;
      }

      // ? Strict format: parse and round-trip (rejects overflows like Feb 30)
      if ($this->format !== null) {
         $Date = DateTimeImmutable::createFromFormat($this->format, $value);

         return $Date !== false && $Date->format($this->format) === $value;
      }

      // :
      return strtotime($value) !== false;
   }
}
