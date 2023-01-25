<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\Response;


class Header
{
   // * Data
   private array $fields;
   // * Meta
   private string $raw;


   public function __construct ()
   {
      // * Data
      $this->fields = [
         'Server' => 'Bootgly',
         'Content-Type' => 'text/html; charset=UTF-8'
      ];
      // * Meta
      $this->raw = '';
   }
   public function __get (string $name)
   {
      switch ($name) {
         case 'fields':
         case 'headers':
            if (\PHP_SAPI !== 'cli') {
               $this->fields = apache_response_headers();
            }

            return $this->fields;

         case 'raw':
            if ($this->raw !== '') {
               return $this->raw;
            }

            $this->reset();

            return $this->raw;

         case 'sent': // TODO refactor
            if (\PHP_SAPI !== 'cli') {
               return headers_sent();
            }

            return null;

         default:
            return $this->get($name);
      }
   }
   public function __set ($name, $value)
   {
      $this->$name = $value;
   }

   public function reset () // @ raw
   {
      // ! FIX bad performance

      foreach ($this->fields as $name => $value) {
         $this->raw .= "$name: $value\r\n";
      }

      $this->raw = rtrim($this->raw);
   }

   public function get (string $name) : string
   {
      return (string) @$this->fields[$name] ?? (string) @$this->fields[strtolower($name)];
   }
   public function set (string $field, string $value = '') // TODO refactor
   {
      $this->fields[$field] = $value;

      if (\PHP_SAPI !== 'cli')
         header($field . ': ' . $value, true);
   }
   public function append (string $field, string $value = '') // TODO refactor
   {
      $this->fields[$field] = $value;

      if (\PHP_SAPI !== 'cli')
         header($field . ': ' . $value, false);
   }
   public function list (array $headers)
   {
      foreach ($headers as $field => $value) {
         if ( is_int($field) ) {
            $this->set($value);
         } else {
            $this->set($field, $value);
         }
      }
   }
}
