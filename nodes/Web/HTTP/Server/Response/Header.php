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
   // * Config
   private array $preset;
   private array $prepared;
   // * Data
   private array $fields;
   // * Meta
   private string $raw;
   private bool $sent;


   public function __construct ()
   {
      // * Config
      $this->preset = [
         'Server' => 'Bootgly'
      ];
      $this->prepared = [];
      // * Data
      $this->fields = [];
      // * Meta
      $this->raw = '';
      $this->sent = false;
   }
   public function __get (string $name)
   {
      switch ($name) {
         // * Config
         case 'preset':
         case 'prepared':
            return $this->$name;
         // * Data
         case 'fields':
         case 'headers':
            if (\PHP_SAPI !== 'cli') {
               $this->fields = apache_response_headers();
            }

            return $this->fields;
         // * Meta
         case 'raw':
            if ($this->raw) {
               return $this->raw;
            }

            $this->build();

            return $this->raw;

         case 'sent':
            if (\PHP_SAPI !== 'cli') {
               return headers_sent();
            }

            return $this->sent;

         default:
            return $this->get($name);
      }
   }
   public function __set ($name, $value)
   {
      switch ($name) {
         // * Config
         case 'pre':
            $this->preset = (array) $value;
         case 'prepared':
            break;
         // *
         default:
            $this->$name = $value;
      }
   }
   public function __isSet ($name)
   {
      return isSet($this->fields[$name]);
   }

   public function clean ()
   {
      $this->fields = [];
   }

   public function prepare (array $fields) // @ Prepare to build
   {
      $this->prepared = $fields;
   }

   // TODO increase performance
   public function build () // @ raw
   {
      // @ Return if empty
      if ( empty($this->fields) && empty($this->prepared) ) {
         return false;
      }

      // @ Set / merge headers with prepared fields
      if ( empty($this->fields) && ! empty($this->prepared) ) {
         $this->fields = $this->prepared;
      } else {
         $this->fields = array_merge($this->fields, $this->prepared);
      }

      // @ Set default headers
      if ( ! isSet($this->fields['Content-Type']) ) {
         $this->fields['Content-Type'] = 'text/html; charset=UTF-8';
      }

      // @ Build
      $raw = [];
      // Preset Fields
      foreach ($this->preset as $name => $value) {
         $raw[] = "$name: $value";
      }
      // Fields
      foreach ($this->fields as $name => $value) {
         $raw[] = "$name: $value";
      }

      $this->raw = implode("\r\n", $raw);

      return true;
   }

   public function get (string $name) : string
   {
      return (string) $this->fields[$name] ?? (string) $this->fields[strtolower($name)] ?? '';
   }
   public function set (string $field, string $value = '') // TODO refactor
   {
      $this->fields[$field] = $value;

      if (\PHP_SAPI !== 'cli')
         header($field . ': ' . $value, true);
   }
   public function append (string $field, string $value = '') // TODO refactor
   {
      if ( isSet($this->fields[$field]) ) {
         // @ Append only value (not entire Header field)

         // TODO map (with const?) Header that can have only value to append, only entire header, etc.

         $this->fields[$field] = $this->fields[$field] . ', ' .$value;   
      } else {
         $this->fields[$field] = $value;
      }

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
