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
   public array $fields;


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

      $this->fields = $fields;
   }
   public function __get (string $name)
   {
      switch ($name) {
         case 'raw':
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
   public function __set (string $fieldName, ?string $fieldValue = null)
   {
      $fieldName = strtolower($fieldName);

      if ($fieldValue === null) {
         $this->fields[$fieldName] = true;
      } else {
         $this->fields[$fieldName] = $fieldValue;
      }
   }

   public function get (string $name)
   {
      return @$this->$name;
   }
}
