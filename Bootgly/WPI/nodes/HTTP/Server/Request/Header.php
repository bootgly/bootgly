<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\nodes\HTTP\Server\Request;


use Bootgly\WPI\nodes\HTTP\Server\Request\Header\Cookie;


class Header
{
   // * Config
   // ...

   // * Data
   public readonly string $raw;
   public null|int|false $length;

   // * Meta
   private array $fields;
   private bool $built;

   public Cookie $Cookie;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      // public $raw (readonly)
      $this->length = null;

      // * Meta
      $this->fields = [];
      $this->built = false;


      $this->Cookie = new Cookie($this);
   }
   public function __get (string $name)
   {
      switch ($name) {
         // * Config
         // ..

         // * Data
         // public $raw (readonly)
         // public $length

         // * Meta
         case 'fields':
            if ($this->built === false) {
               $this->build();
            }

            return $this->fields;

         default:
            return $this->get($name);
      }
   }

   public function get (string $name) : string|array
   {
      if ($this->built === false) {
         $this->build();
      }

      return @$this->fields[$name] ?? @$this->fields[strtolower($name)] ?? '';
   }
   public function set (string $raw) : void
   {
      $this->raw = $raw;
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
            if ( isSet($fields[$key]) ) {
               if ( is_string($fields[$key]) ) {
                  $fields[$key] = [
                     $fields[$key]
                  ];
               }

               $fields[$key][] = $value;
            } else {
               $fields[$key] = $value;
            }
         }
      }

      $this->fields = $fields;

      $this->built = true;

      return true;
   }
}
