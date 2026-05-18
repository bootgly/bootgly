<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw;


use function dechex;
use function is_array;
use function is_object;
use function is_resource;
use function is_scalar;
use function is_string;
use function json_encode;
use function method_exists;
use function strlen;
use function strval;


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw = '';

   // * Metadata
   // @ `length` is a virtual property kept for back-compat; hot path in
   // Response/Raw::encode() bypasses this hook and calls strlen() directly.
   public protected(set) int $length {
      get => strlen($this->raw);
      set (int $value) {
         // @ Accepted for back-compat (e.g. HTTP Client decoded body length),
         // but value is recomputed from $raw on each read — effectively a no-op.
         // Kept to preserve the write API without breaking existing callers.
      }
   }
   // Encoded
   public protected(set) string $chunked {
      get => dechex(strlen($this->raw)) . "\r\n{$this->raw}\r\n";
      set (string $value) {
         // @ No-op setter (see $length note); chunked is derived from $raw.
      }
   }

   public function __construct ()
   {
      // * Data
      $this->raw = '';
   }

   /**
    * Convert one response body value to text.
    */
   public function stringify (mixed $value): string
   {
      if (is_string($value)) {
         return $value;
      }

      if ($value === null || is_scalar($value)) {
         return strval($value);
      }

      if (is_object($value)) {
         if (method_exists($value, '__toString')) {
            return (string) $value;
         }

         $encodedObject = json_encode($value);
         return $encodedObject === false ? '' : $encodedObject;
      }

      if (is_array($value)) {
         $encodedArray = json_encode($value);
         return $encodedArray === false ? '' : $encodedArray;
      }

      if (is_resource($value)) {
         return '';
      }

      return '';
   }
}
