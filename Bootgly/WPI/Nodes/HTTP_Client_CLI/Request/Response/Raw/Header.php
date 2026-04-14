<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Raw;


use function explode;
use function implode;
use function is_array;
use function is_string;
use function strlen;
use function strpos;
use function strtolower;
use function substr;


class Header
{
   // * Config
   // ...

   // * Data
   /** @var array<string, string|array<int,string>> */
   public array $fields;
   public string $raw;

   // * Metadata
   public null|int $length;
   protected bool $built;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->fields = [];
      $this->raw = '';

      // * Metadata
      $this->length = null;
      $this->built = false;
   }

   /**
    * Get a header field value by name.
    *
    * @param string $name
    *
    * @return string|null
    */
   public function get (string $name): ?string
   {
      if ($this->built === false) {
         $this->build();
      }

      // @ Keys are normalized to lowercase
      $value = $this->fields[strtolower($name)] ?? null;

      if ($value === null) {
         return null;
      }

      if (is_array($value)) {
         return implode(', ', $value);
      }

      return (string) $value;
   }

   /**
    * Get all values for a header field as an array.
    * Use this for headers that should not be combined (e.g., Set-Cookie).
    *
    * @param string $name
    *
    * @return array<int, string>
    */
   public function getAll (string $name): array
   {
      if ($this->built === false) {
         $this->build();
      }

      $value = $this->fields[strtolower($name)] ?? null;

      if ($value === null) {
         return [];
      }

      if (is_array($value)) {
         return $value;
      }

      return [(string) $value];
   }

   /**
    * Define the raw header string from the response.
    *
    * @param string $raw
    *
    * @return void
    */
   public function define (string $raw): void
   {
      $this->raw = $raw;
      $this->length = strlen($raw);
      $this->built = false;
   }

   /**
    * Build/parse fields from the raw header string.
    *
    * @return bool
    */
   public function build (): bool
   {
      $fields = [];

      foreach (explode("\r\n", $this->raw) as $field) {
         // @ RFC 9110: field-line = field-name ":" OWS field-value OWS
         // OWS = optional whitespace (SP / HTAB)
         $colonPos = strpos($field, ':');
         if ($colonPos === false) {
            continue;
         }

         $key = strtolower(substr($field, 0, $colonPos));
         $value = trim(substr($field, $colonPos + 1), " \t");

         if (isSet($fields[$key])) {
            if (is_string($fields[$key])) {
               $fields[$key] = [$fields[$key]];
            }

            $fields[$key][] = $value;
         }
         else {
            $fields[$key] = $value;
         }
      }

      // * Data
      $this->fields = $fields;
      // * Metadata
      $this->built = true;

      return true;
   }
}
