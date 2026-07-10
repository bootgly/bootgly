<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Validation;


use Bootgly\ABI\Data\Language;


abstract class Condition
{
   // * Config
   public protected(set) bool $implicit = false;
   public protected(set) string $message;

   // * Data
   // ...

   // * Metadata
   /**
    * Natural-source message template — the key looked up in the `validation` domain catalogs.
    */
   protected string $template = '{field} is invalid.';
   /**
    * Extra `{token}` replacements merged with `{field}` before translation.
    *
    * @var array<string,string>
    */
   protected array $substitutions = [];


   public function __construct (string $message = '')
   {
      // * Config
      $this->message = $message;
   }

   /**
    * @param array<string,mixed> $data
    */
   abstract public function validate (string $field, mixed $value, array $data): bool;

   public function format (string $field): string
   {
      // ! User override (kept translatable) or the rule's default template
      $message = $this->message !== '' ? $this->message : $this->template;

      // :
      return Language::translate(
         $message,
         ['field' => $field] + $this->substitutions,
         domain: 'validation'
      );
   }
}
