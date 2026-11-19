<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Debugging\Data\Vars\Redactable;


use function in_array;
use function is_array;
use function is_string;
use function strtolower;
use Attribute;

use Bootgly\ABI\Data\URI;


/**
 * Marks a property whose value never reaches a debug dump (`var_dump`,
 * `print_r`, `debug_zval_dump`, `Dumper`) of an object that uses `Redactable`.
 *
 * - No keys: the whole value is masked.
 * - Keys: only the entries under those keys (any letter case, at any depth)
 *   of an array value are masked — `#[Secret(['password'])]`.
 * - `URI: true`: a string value (or each string inside an array) loses the
 *   userinfo of every URL in it, by `URI::redact()`.
 *
 * `null` and `''` show as they are, so a dump still tells "not configured".
 * Mark the property that stores the value, never a virtual hook over it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Secret
{
   /** What a dump shows in place of a secret. */
   public const string MASK = '[redacted]';

   // * Config
   /**
    * Array keys to mask inside the value, lowercased; empty: the whole value.
    *
    * @var array<int,string>
    */
   public private(set) array $keys;
   /** Redact the userinfo of the URLs in a string value instead of masking it. */
   public private(set) bool $URI;


   /**
    * @param array<int,string> $keys Array keys to mask inside the value; empty: the whole value.
    * @param bool $URI Redact the userinfo of the URLs in a string value instead of masking it.
    */
   public function __construct (array $keys = [], bool $URI = false)
   {
      // * Config
      $this->keys = [];
      foreach ($keys as $key) {
         $this->keys[] = strtolower($key);
      }
      $this->URI = $URI;
   }

   /**
    * Redact a value for a dump. The value is never written: arrays are
    * rebuilt, so a reference held inside one keeps its live value.
    *
    * @param mixed $value The property's value.
    *
    * @return mixed The mask, the value with its keyed entries masked or its
    *               URLs redacted, or the value itself when it is `null`, `''`
    *               or carries none of the keys.
    */
   public function redact (mixed $value): mixed
   {
      // ?: Absent or empty — shows as it is
      if ($value === null || $value === '') {
         return $value;
      }
      // ?: A URL-bearing string
      if ($this->URI === true && is_string($value) === true) {
         return URI::redact($value);
      }
      // ?: Whole value
      if ($this->keys === [] && $this->URI === false) {
         return self::MASK;
      }
      // ?: Keyed — only an array carries keys
      if (is_array($value) === false) {
         return $value;
      }

      // @@ Rebuilt by value, never assigned into
      $redacted = [];
      foreach ($value as $key => $entry) {
         $redacted[$key] = match (true) {
            is_string($key) === true && in_array(strtolower($key), $this->keys, true) === true
               => $entry === null || $entry === '' ? $entry : self::MASK,
            is_array($entry) === true || ($this->URI === true && is_string($entry) === true)
               => $this->redact($entry),
            default => $entry
         };
      }

      // :
      return $redacted;
   }
}
