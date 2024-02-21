<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw;


use Bootgly\WPI\Modules\HTTP\Server\Request\Heading;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Header\Cookies;


class Header
{
   use Heading;


   // * Config
   // ...

   // * Data
   protected string $raw;
   protected array $fields;

   // * Metadata
   private bool $built;
   private null|int|false $length;

   public Cookies $Cookies;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      // field
      $fields = \apache_request_headers();
      if ($fields !== false) {
         $fields = \array_change_key_case($fields, \CASE_LOWER);
      }
      else {
         $fields = [];
      }
      $this->fields = $fields;

      // * Metadata
      $this->built = ! empty($fields);
      $this->length = null;


      $this->Cookies = new Cookies($this);
   }
   public function __get (string $name)
   {
      switch ($name) {
         // * Config
         // ..

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

         // * Metadata
         case 'length':
            return $this->length = \strlen($this->raw ?? $this->__get('raw'));
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
      }
   }

   public function set (string $name, string $value) : bool
   {
      if ( isSet($this->fields[$name]) ) {
         return false;
      }

      $this->fields[$name] = $value;

      return true;
   }
}
