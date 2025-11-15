<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw;


use function explode;
use function implode;
use function is_array;
use function is_string;
use function strlen;
use function strpos;
use function strtolower;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header\Cookies;


class Header
{
   protected Cookies $Cookies;

   // * Config
   // ... inherited

   // * Data
   /** @var array<string, string|array<int,string>> */
   public array $fields {
      get {
         if ($this->built === false) {
            $this->build();
         }

         return $this->fields;
      }
   }
   public readonly string $raw;

   // * Metadata
   public readonly null|int|false $length;
   protected bool $built;


   public function __construct ()
   {
      // * Config
      // ... inherited

      // * Data
      // fields
      $this->fields = [];

      // * Metadata
      // built
      $this->built = false;
      #$this->length = strlen($raw);
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         case 'Cookies':
            return $this->Cookies ??= new Cookies($this);

         // * Config
         // ..

         // * Data
         // public $raw (readonly)
         case 'fields':
            if ($this->built === false) {
               $this->build();
            }

            return $this->fields;

         // * Metadata
         // public length (readonly)
      }

      return null;
   }

   public function define (string $raw): void
   {
      // * Data
      $this->raw ??= $raw;
      // * Metadata
      $this->length ??= strlen($raw);
   }

      /**
    * Get a field from the Request Header
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

      $value = $this->fields[$name] ?? $this->fields[strtolower($name)] ?? null;

      if ($value === null) {
         return null;
      }

      if (is_array($value)) {
         $normalized = [];
         foreach ($value as $entry) {
            $entryString = (string) $entry;
            if ($entryString === '') {
               continue;
            }

            $normalized[] = $entryString;
         }

         if ($normalized === []) {
            return null;
         }

         $glue = strtolower($name) === 'cookie' ? '; ' : ', ';

         return implode($glue, $normalized);
      }

      $stringValue = (string) $value;

      return $stringValue === '' ? null : $stringValue;
   }

   /**
    * Append a field to the Request Header
    *
    * @param string $name Field name
    * @param string $value Field value
    *
    * @return bool 
    */
   public function append (string $name, string $value): bool
   {
      if ( isSet($this->fields[$name]) ) {
         return false;
      }

      $this->fields[$name] = $value;

      return true;
   }

   /**
    * Build fields from the Request Header
    *
    * @return bool 
    */
   public function build (): bool
   {
      $fields = [];

      foreach (explode("\r\n", $this->raw ?? '') as $field) {
         if ( strpos($field, ': ') ) {
            @[$key, $value] = explode(': ', $field, 2);

            #if ( strpos($key, ' ') ) {
            #   return false; // @ 400 Bad Request
            #}

            if ( isSet($fields[$key]) ) {
               if ( is_string($fields[$key]) ) {
                  $fields[$key] = [
                     $fields[$key]
                  ];
               }

               $fields[$key][] = $value;
            }
            else {
               $fields[$key] = $value;
            }
         }
      }

      // * Data
      $this->fields = $fields;
      // * Metadata
      $this->built = true;

      return true;
   }
}
