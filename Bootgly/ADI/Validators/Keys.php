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



use function array_key_exists;
use function array_values;
use function is_array;
use function is_string;
use InvalidArgumentException;

use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;


/**
 * An array whose keys are all among the allowed ones — a closed object.
 * Keys are compared as strings, so a list passes only when its indexes
 * (`'0'`, `'1'`, …) are allowed. Missing keys are not this rule's concern:
 * give each required key its own `Required`.
 */
class Keys extends Condition implements Descriptor
{
   // * Config
   /**
    * The allowed keys.
    *
    * @var array<int,string>
    */
   public private(set) array $keys;

   // * Data
   // ...

   // * Metadata
   /**
    * The allowed keys as a lookup set.
    *
    * @var array<string,true>
    */
   private array $allowed = [];
   protected string $template = '{field} has keys that are not allowed.';


   /**
    * @param array<int,string> $keys The allowed keys.
    *
    * @throws InvalidArgumentException When a key is not a string.
    */
   public function __construct (array $keys, string $message = '')
   {
      foreach ($keys as $key) {
         // ? The PHPDoc type is not enforced at runtime
         if (is_string($key) === false) { // @phpstan-ignore function.alreadyNarrowedType, identical.alwaysFalse
            throw new InvalidArgumentException('Allowed keys must be strings.');
         }
      }

      parent::__construct($message);

      // * Config
      $this->keys = array_values($keys);

      // * Metadata
      foreach ($this->keys as $key) {
         $this->allowed[$key] = true;
      }
   }

   /**
    * Pass when the value is an array with no key outside the allowed ones.
    *
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      if (is_array($value) === false) {
         return false;
      }

      foreach ($value as $key => $item) {
         if (array_key_exists((string) $key, $this->allowed) === false) {
            return false;
         }
      }

      // :
      return true;
   }

   /**
    * JSON Schema: `type: object` with `propertyNames: {enum: keys}` — the
    * closed-object constraint, whatever other rules declare as properties
    * (`maxProperties: 0` when no key is allowed).
    *
    * @return null|array<string,mixed>
    */
   public function describe (): null|array
   {
      // ?: No key allowed — an empty `enum` is discouraged by JSON Schema
      if ($this->keys === []) {
         return ['type' => 'object', 'maxProperties' => 0];
      }

      // :
      return ['type' => 'object', 'propertyNames' => ['enum' => $this->keys]];
   }
}
