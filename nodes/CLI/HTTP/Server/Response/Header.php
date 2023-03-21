<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\HTTP\Server\Response;


use Bootgly\CLI\HTTP\Server\Response\Header\Cookie;


class Header
{
   // * Config
   private array $preset;
   private array $prepared;

   // * Data
   private array $fields;

   // * Meta
   private array $queued;
   private string $raw;
   private bool $sent;

   public Cookie $Cookie;


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
      $this->queued = [];
      $this->raw = '';
      $this->sent = false;


      $this->Cookie = new Cookie($this);
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
            return $this->fields;
         // * Meta
         case 'queued':
            return $this->queued;
         case 'raw':
            if ($this->raw) {
               return $this->raw;
            }

            $this->build();

            return $this->raw;

         case 'sent':
            return $this->sent;

         default:
            return $this->get($name);
      }
   }
   public function __set ($name, $value)
   {
      switch ($name) {
         // * Config
         case 'preset':
            $this->preset = (array) $value;
            break;
         case 'prepared':
         // * Data
         // case 'fields':
         // * Meta
         case 'queued':
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
      $this->prepared = [];
   }

   public function prepare (array $fields) // @ Prepare to build
   {
      $this->prepared = $fields;
   }
   public function append (string $field, string $value = '', ? string $separator = ', ') // TODO refactor
   {
      // TODO map separator (with const?) Header that can have only value to append, only entire header, etc.

      if ( isSet($this->fields[$field]) ) {
         $this->fields[$field] .= $separator . $value;
      } else {
         $this->fields[$field] = $value;
      }
   }
   public function queue (string $field, string $value = '')
   {
      $this->queued[] = "$field: $value";
   }

   public function translate (string $field, ...$values) : string
   {
      switch ($field) {
         case 'Content-Range':
            // @ bytes Context
            $start = $values[0];
            $end = $values[1];
            $size = $values[2];

            if ($end > $size - 1) {
               $end += 1;
            }

            return "bytes {$start}-{$end}/{$size}";
         default:
            return '';
      }
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
      $this->fields['Content-Type'] ??= 'text/html; charset=UTF-8';

      // @ Build
      $queued = $this->queued;
      // Preset Fields
      foreach ($this->preset as $name => $value) {
         $queued[] = "$name: $value";
      }
      // Fields
      foreach ($this->fields as $name => $value) {
         $queued[] = "$name: $value";
      }

      $this->raw = implode("\r\n", $queued);

      return true;
   }

   public function get (string $name) : string
   {
      return (string) $this->fields[$name] ?? (string) $this->fields[strtolower($name)] ?? '';
   }

   public function set (string $field, string $value)
   {
      $this->fields[$field] = $value;
   }
}
