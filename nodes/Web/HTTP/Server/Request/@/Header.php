<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\_;


use Bootgly\Web\HTTP\Server\_\Header\Cookie;


class Header
{
   // * Data
   protected string $raw;
   public array $fields;
   // * Meta
   public bool $built;
   public null|int|false $length;

   public Cookie $Cookie;


   public function __construct ()
   {
      $fields = false;

      if (\PHP_SAPI !== 'cli') {
         $fields = apache_request_headers();
      }

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
      $this->build();

      return (string) $this->fields[$name] ?? (string) $this->fields[strtolower($name)] ?? '';
   }

   public function build ()
   {
      if ($this->built) {
         return false;
      }

      $fields = [];

      foreach (explode("\r\n", $this->raw) as $field) {
         if (strpos($field, ': ') !== false) {

            @[$key, $value] = explode(': ', $field, 2);

            $fields[$key] = $value;
         }
      }

      $this->fields = $fields;

      $this->built = true;

      return true;
   }
}
