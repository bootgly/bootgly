<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw;


use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function strtolower;

use InvalidArgumentException;


class Header
{
   // * Config
   // ...

   // * Data
   /** @var array<string,string|array<int,string>> */
   public array $fields;
   public string $raw;

   // * Metadata
   protected bool $built;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->fields = [];
      $this->raw = '';

      // * Metadata
      $this->built = false;
   }

   /**
    * Set a header field (replaces existing value).
    *
    * @param string $name
    * @param string $value
    *
    * @return void
    *
    * @throws InvalidArgumentException If name or value is invalid.
    */
   public function set (string $name, string $value): void
   {
      $this->validate($name, $value);

      $this->fields[$name] = $value;
      $this->built = false;
   }

   /**
    * Get a header field value.
    *
    * @param string $name
    *
    * @return string|null
    */
   public function get (string $name): ?string
   {
      $value = $this->fields[$name] ?? $this->fields[strtolower($name)] ?? null;

      if ($value === null) {
         return null;
      }

      if (is_array($value)) {
         return implode(', ', $value);
      }

      return (string) $value;
   }

   /**
    * Append a header field value (adds to existing or creates new).
    *
    * @param string $name
    * @param string $value
    *
    * @return void
    *
    * @throws InvalidArgumentException If name or value is invalid.
    */
   public function append (string $name, string $value): void
   {
      $this->validate($name, $value);

      if (isSet($this->fields[$name])) {
         if (is_string($this->fields[$name])) {
            $this->fields[$name] = [$this->fields[$name]];
         }

         $this->fields[$name][] = $value;
      }
      else {
         $this->fields[$name] = $value;
      }

      $this->built = false;
   }

   /**
    * Remove a header field.
    *
    * @param string $name
    *
    * @return void
    */
   public function remove (string $name): void
   {
      unset($this->fields[$name]);
      $this->built = false;
   }

   /**
    * Build the raw header string from fields.
    *
    * @return string
    */
   public function build (): string
   {
      $raw = '';

      foreach ($this->fields as $name => $value) {
         if (is_array($value)) {
            foreach ($value as $v) {
               $raw .= "{$name}: {$v}\r\n";
            }
         }
         else {
            $raw .= "{$name}: {$value}\r\n";
         }
      }

      $this->raw = $raw;
      $this->built = true;

      return $raw;
   }

   /**
    * Validate header name and value against HTTP grammar.
    *
    * @param string $name
    * @param string $value
    *
    * @return void
    *
    * @throws InvalidArgumentException If name or value is invalid.
    */
   protected function validate (string $name, string $value): void
   {
      // @ RFC 9110: token = 1*tchar
      // tchar = "!" / "#" / "$" / "%" / "&" / "'" / "*" / "+" / "-" / "." /
      //         "^" / "_" / "`" / "|" / "~" / DIGIT / ALPHA
      if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
         throw new InvalidArgumentException("Invalid header name: {$name}");
      }

      // @ RFC 9110: field-value must not contain CR or LF (CRLF injection)
      if (preg_match('/[\r\n]/', $value) === 1) {
         throw new InvalidArgumentException('Invalid header value: contains CR/LF');
      }
   }
}
