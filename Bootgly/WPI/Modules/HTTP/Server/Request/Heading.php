<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 *
 * PHP version adapted from NPM range/parser:
 * (https://github.com/jshttp/range-parser/blob/master/index.js)
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Request;


trait Heading
{
   public function get (string $name) : string|array
   {
      if ($this->built === false) {
         $this->build();
      }

      return (@$this->fields[$name] ?? @$this->fields[\strtolower($name)] ?? '');
   }

   public function build () : bool
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