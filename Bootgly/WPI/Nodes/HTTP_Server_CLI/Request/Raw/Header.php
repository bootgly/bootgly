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


use Bootgly\WPI\Modules\HTTP\Server\Request\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header\Cookies;


class Header extends Raw\Header
{
   private Cookies $Cookies;

   // * Config
   // ...

   // * Data
   // ... inherited
   public readonly string $raw;

   // * Metadata
   // ... inherited
   public readonly null|int|false $length;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      // fields
      $this->fields = [];

      // * Metadata
      // built
      $this->built = false;
      #$this->length = \strlen($raw);
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

   public function set (string $raw): void
   {
      // * Data
      $this->raw ??= $raw;
      // * Metadata
      $this->length ??= \strlen($raw);
   }

   /**
    * Build fields from the Request Header
    *
    * @return bool 
    */
   public function build (): bool
   {
      $fields = [];

      foreach (\explode("\r\n", $this->raw ?? '') as $field) {
         if ( \strpos($field, ': ') ) {
            @[$key, $value] = \explode(': ', $field, 2);

            #if ( strpos($key, ' ') ) {
            #   return false; // @ 400 Bad Request
            #}

            if ( isSet($fields[$key]) ) {
               if ( \is_string($fields[$key]) ) {
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

      $this->fields = $fields;

      $this->built = true;

      return true;
   }
}
