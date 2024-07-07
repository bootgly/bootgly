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


use Bootgly\WPI\Modules\HTTP\Server\Request\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Header\Cookies;


class Header extends Raw\Header
{
   public Cookies $Cookies;

   // * Config
   // ...

   // * Data
   // ... inherited
   protected string $raw;

   // * Metadata
   // ... inherited
   private null|int|false $length;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      // fields
      $headers = \apache_request_headers();
      $fields = \array_change_key_case($headers, \CASE_LOWER);
      $this->fields = $fields;

      // * Metadata
      // built
      $this->built = ! empty($fields);
      $this->length = null;


      $this->Cookies = new Cookies($this);
   }
   public function __get (string $name): mixed
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
            $this->length = \strlen($this->raw ?? $this->__get('raw'));
            return $this->length;
      }

      return null;
   }

   public function build (): bool
   {
      if ( $this->built === true ) {
         return false;
      }

      $this->built = true;

      return true;
   }
}
