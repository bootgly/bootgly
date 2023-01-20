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


class Header
{
   // * Data
   protected string $raw;
   public array $fields;
   // * Meta
   public null|int|false $length;


   public function __construct ()
   {
      $fields = false;

      if (\PHP_SAPI !== 'cli') {
         $fields = apache_request_headers();
      }

      if ($fields !== false) {
         $fields = array_change_key_case($fields, CASE_LOWER);
      } else {
         $fields = [];
      }

      // * Data
      $this->fields = $fields;
      // * Meta
      $this->length = null;
   }
   public function __get (string $name)
   {
      switch ($name) {
         case 'raw':
            if ( isSet($this->raw) ) {
               return $this->raw;
            }

            $raw = '';
            foreach ($this->fields as $name => $value) {
               $raw .= "$name: $value\r\n";
            }
            return $raw;
         default:
            $name = strtolower($name);
            return @$this->fields[$name];
      }
   }
   public function __set (string $name, string $value)
   {
      switch ($name) {
         case 'raw':
            $this->raw = $value;
            break;
         default:
            $this->fields[$name] = $value;
      }
   }

   public function get (string $name)
   {
      return @$this->$name;
   }
}
