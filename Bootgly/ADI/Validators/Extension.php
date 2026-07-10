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


use const PATHINFO_EXTENSION;
use function array_map;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function pathinfo;
use function strtolower;

use Bootgly\ADI\Validation\Condition;


class Extension extends Condition
{
   // * Config
   /**
    * @var array<int,string>
    */
   public private(set) array $extensions;

   // * Metadata
   protected string $template = '{field} must have an allowed extension.';


   /**
    * @param string|array<int,string> $extensions
    */
   public function __construct (string|array $extensions, string $message = '')
   {
      parent::__construct($message);

      // * Config
      $this->extensions = array_map(
         static fn (string $extension): string => strtolower(ltrim($extension, '.')),
         is_array($extensions) ? $extensions : [$extensions]
      );
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      if (is_array($value) === false) {
         return false;
      }

      if (($value['error'] ?? null) !== 0) {
         return false;
      }

      $name = $value['name'] ?? null;

      if (is_string($name) === false) {
         return false;
      }

      $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

      return in_array($extension, $this->extensions, true);
   }
}
