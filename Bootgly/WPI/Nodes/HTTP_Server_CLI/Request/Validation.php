<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


use function array_key_exists;
use function is_array;
use InvalidArgumentException;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Validation\Condition;


class Validation
{
   // * Config
   /**
    * @var array<string,mixed>
    */
   private array $source;
   /**
    * @var array<string,Condition|array<int,Condition>>
    */
   private array $rules;

   // * Data
   /**
    * @var array<string,array<int,string>>
    */
   public private(set) array $errors = [];

   // * Metadata
   public bool $valid {
      get => $this->errors === [];
   }


   /**
    * @param array<string,mixed> $source
    * @param array<string,Condition|array<int,Condition>> $rules
    */
   public function __construct (array $source, array $rules)
   {
      // * Config
      $this->source = $source;
      $this->rules = $rules;

      // * Data
      $this->errors = [];

      // @
      $this->execute();
   }

   private function execute (): void
   {
      foreach ($this->rules as $field => $conditions) {
         $field = (string) $field;
         /** @var mixed $raw */
         $raw = $conditions;

         if ($raw instanceof Condition) {
            $raw = [$raw];
         }

         if (is_array($raw) === false) {
            throw new InvalidArgumentException("Validation rules for {$field} must be Condition objects.");
         }

         $exists = array_key_exists($field, $this->source);
         $value = $exists ? $this->source[$field] : null;
         $blank = $value === null || $value === '' || (is_array($value) && $value === []);

         foreach ($raw as $Condition) {
            if ($Condition instanceof Condition === false) {
               throw new InvalidArgumentException("Validation rule for {$field} must be a Condition object.");
            }

            // ? Optional empty fields are ignored unless the rule is implicit.
            if ($blank && $Condition->implicit === false) {
               continue;
            }

            if ($Condition->validate($field, $value, $this->source) === false) {
               $this->errors[$field][] = $Condition->format($field);
            }
         }
      }
   }
}
