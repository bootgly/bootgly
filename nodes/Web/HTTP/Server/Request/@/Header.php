<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\Request\_;


use Bootgly\Web\HTTP\Server\Request\_\Header\Cookie;


class Header
{
   // * Config
   // ...

   // * Data
   protected string $raw;
   private array $fields;

   // * Meta
   private bool $built;
   public null|int|false $length;

   public Cookie $Cookie;


   public function __construct ()
   {
      $fields = apache_request_headers();

      if ($fields !== false) {
         $fields = array_change_key_case($fields, CASE_LOWER);

         $this->built = true;
      } else {
         $fields = [];

         $this->built = false;
      }

      // * Data
      $this->fields = $fields;
      // * Meta
      $this->length = null;


      $this->Cookie = new Cookie($this);
   }
   public function __get (string $name)
   {
      switch ($name) {
         // * Data
         case 'raw':
            if ( isSet($this->raw) && $this->raw !== '' ) {
               return $this->raw;
            }

            $raw = '';
            foreach ($this->fields as $name => $value) {
               $raw .= "$name: $value\r\n";
            }

            return $raw;
         case 'fields':
            return $this->fields;

         default:
            return $this->get($name);
      }
   }
   public function __set (string $name, string $value)
   {
      switch ($name) {
         // * Data
         case 'raw':
            $this->raw = $value;
            break;
         case 'fields':
            break;

         default:
            $this->fields[$name] = $value;
      }
   }

   public function get (string $name) : string
   {
      if ($this->built === false) {
         $this->build();
      }

      return (string) @$this->fields[$name] ?? (string) @$this->fields[strtolower($name)] ?? '';
   }

   public function build () : bool
   {
      $fields = [];

      foreach (explode("\r\n", $this->raw) as $field) {
         if ( strpos($field, ': ') ) {
            @[$key, $value] = explode(': ', $field, 2);

            #if ( strpos($key, ' ') ) {
            #   return false; // @ 400 Bad Request
            #}

            $fields[$key] = $value;
         }
      }

      $this->fields = $fields;

      $this->built = true;

      return true;
   }
}
