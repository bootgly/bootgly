<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment\Configs\Schema;


use function preg_match;
use InvalidArgumentException;

use Bootgly\ADI\Validation\Condition;
use Bootgly\API\Environment\Configs\Config\Types;


/**
 * One declared key of a config `Schema`.
 *
 * `key`, `default`, `cast` and `required` mean exactly what they mean for
 * `Config::bind()`: the value comes from the process environment, then the
 * scope-local `.env`, then `default`; a required field ignores `default` and
 * fails when the value is absent or an empty string; `cast` parses a scalar
 * strictly. `Rules` are ADI validation conditions run on the resolved (and
 * cast) value.
 */
class Field
{
   // * Config
   /**
    * The environment variable name; `''` binds `default` only.
    */
   public private(set) string $key;
   /**
    * The value used when the key is not set (never used by a required field).
    */
   public private(set) mixed $default;
   /**
    * The strict scalar type the value is parsed as, or `null` to keep it.
    */
   public private(set) null|Types $cast;
   /**
    * Whether an absent or empty value is a failure.
    */
   public private(set) bool $required;
   /**
    * The ADI validation conditions the resolved value must pass.
    *
    * @var array<int,Condition>
    */
   public private(set) array $Rules;


   /**
    * @param string $key The environment variable name (`''` for a default-only field).
    * @param mixed $default The value used when the key is not set.
    * @param null|Types $cast The strict scalar type to parse the value as.
    * @param bool $required Whether an absent or empty value fails (the default is then ignored).
    * @param array<int,Condition> $Rules The ADI validation conditions run on the resolved value.
    *
    * @throws InvalidArgumentException When the key holds a control character or `=`, a
    *   required field has no key (it could never resolve), or a rule is not a `Condition`.
    */
   public function __construct (
      string $key = '',
      mixed $default = null,
      null|Types $cast = null,
      bool $required = false,
      array $Rules = []
   )
   {
      // ? A key the environment could hold, and that cannot forge report lines
      if (preg_match('/[\x00-\x1F\x7F=]/', $key) === 1) {
         throw new InvalidArgumentException('Config schema field keys cannot hold control characters or "=".');
      }
      // ? A required field without a key can never resolve
      if ($required && $key === '') {
         throw new InvalidArgumentException('A required config schema field needs a key.');
      }
      // ? Rules are ADI conditions
      /** @var array<mixed,mixed> $raw Runtime-checked below */
      $raw = $Rules;
      foreach ($raw as $Rule) {
         if ($Rule instanceof Condition === false) {
            throw new InvalidArgumentException('Config schema rules must be Condition objects.');
         }
      }

      // * Config
      $this->key = $key;
      $this->default = $default;
      $this->cast = $cast;
      $this->required = $required;
      $this->Rules = $Rules;
   }
}
