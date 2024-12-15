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


use function apache_request_headers;
use function array_change_key_case;
use function strlen;
use const CASE_LOWER;

use Bootgly\WPI\Modules\HTTP\Server\Request\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_\Request\Raw\Header\Cookies;


class Header extends Raw\Header
{
   public Cookies $Cookies;

   // * Config
   // ... inherited

   // * Data
   /** @var array<string|array<string>> */
   public array $fields {
      get => $this->fields;  
   }
   // .. $raw

   // * Metadata
   // .. $length;
   // .. $built;


   public function __construct ()
   {
      // * Config
      // ... inherited

      // * Data
      // fields
      $headers = apache_request_headers();
      $fields = array_change_key_case($headers, CASE_LOWER);
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

         // * Metadata
         case 'length':
            $this->length = strlen($this->raw ?? $this->__get('raw'));
            return $this->length;
      }

      return null;
   }

   /**
    * Build fields from the Request Header
    *
    * @return bool 
    */
   public function build (): bool
   {
      if ( $this->built === true ) {
         return false;
      }

      $this->built = true;

      return true;
   }
}
