<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validators;


use const PATHINFO_EXTENSION;
use function array_map;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function pathinfo;
use function strtolower;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Condition;


class Extension extends Condition
{
   // * Config
   /**
    * @var array<int,string>
    */
   public private(set) array $extensions;


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

   public function format (string $field): string
   {
      if ($this->message !== '') {
         return $this->message;
      }

      return "{$field} must have an allowed extension.";
   }
}
