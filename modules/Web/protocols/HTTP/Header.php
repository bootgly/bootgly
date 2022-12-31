<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\protocols\HTTP;


use Bootgly\Web\protocols\HTTP\Header\Cookie;


abstract class Header
{
   private array $fields;

   public Cookie $Cookie;


   public function __construct (? array $headers = null)
   {
      // if ($this instanceof Server)

      $headers = $headers !== null ? $headers : apache_request_headers();

      if ($headers !== false) {
         $headers = array_change_key_case($headers, CASE_LOWER);
      } else {
         $headers = [];
      }
      $this->fields = $headers;

      $this->Cookie = new Cookie();
   }
   public function __get (string $name)
   {
      switch ($name) {
         case 'headers':
         case 'fields':
            return $this->fields;

         case 'raw':
            $raw = '';
            foreach ($this->fields as $name => $value) {
               $raw .= "$name: $value\r\n";
            }

            return $raw;
         default:
            return $this->get($name);
      }
   }

   public function get (string $name)
   {
      $name = strtolower($name);
      return @$this->fields[$name];
   }
}
