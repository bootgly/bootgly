<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;


use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response\Header\Cookie;


class Header
{
   // * Config
   // ...

   // * Data
   protected array $preset;
   protected array $prepared;
   protected array $fields;
   public string $raw;

   // * Meta
   private array $queued;
   private int $built;

   public Cookie $Cookie;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->preset = [
         'Server' => 'Bootgly',
         'Date' => true
      ];
      $this->prepared = [];
      $this->fields = [];
      $this->raw = '';

      // * Meta
      $this->queued = [];
      $this->built = 0;

      // @
      $this->Cookie = new Cookie($this);
   }
   public function __get (string $name)
   {
      switch ($name) {
         // * Config
         // ...

         // * Data
         case 'preset':
         case 'prepared':
            return $this->$name;
         case 'fields':
         case 'headers':
            return $this->fields;

         // * Meta
         case 'queued':
            return $this->queued;
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
         // ...

         // * Data
         case 'preset':
            $this->preset = (array) $value;
            break;
         case 'prepared':
            break;
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

   public function preset (string $name, ? string $value = null)
   {
      if ($value) {
         $this->preset[$name] = $value;
      } else {
         unset($this->preset[$name]);
      }
   }
   public function prepare (array $fields) // @ Prepare to build
   {
      $this->prepared = $fields;
   }
   public function append (string $field, string $value = '', ? string $separator = ', ')
   {
      // TODO map separator (with const?)
      // Header that can have only value to append, only entire header, etc.

      if ( isSet($this->fields[$field]) ) {
         $this->fields[$field] .= $separator . $value;
      } else {
         $this->fields[$field] = $value;
      }
   }
   public function queue (string $field, string $value = '')
   {
      if ($field) {
         $this->queued[] = "$field: $value";

         return true;
      }

      return false;
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

   public function get (string $name) : string
   {
      return (string) @$this->fields[$name] ?? (string) @$this->fields[strtolower($name)] ?? '';
   }
   public function set (string $field, string $value) : bool
   {
      if ($field) {
         $this->fields[$field] = $value;

         return true;
      }

      return false;
   }

   // TODO increase performance
   public function build () : true // @ raw
   {
      if (time() === $this->built) {
         return true;
      }

      // @ Merge fields
      $fields = $this->preset + $this->fields + $this->prepared;

      // @ Set default headers
      $fields['Content-Type'] ??= 'text/html; charset=UTF-8';

      // @ Build headers
      $queued = $this->queued;
      // Fields
      foreach ($fields as $name => $value) {
         // Dynamic fields
         if ($value === true) {
            $value = match ($name) {
               'Date' => gmdate('D, d M Y H:i:s \G\M\T'),
               default => ''
            };
         }

         $queued[] = "$name: $value";
      }

      $this->raw = implode("\r\n", $queued);

      $this->built = time();

      return true;
   }
}
