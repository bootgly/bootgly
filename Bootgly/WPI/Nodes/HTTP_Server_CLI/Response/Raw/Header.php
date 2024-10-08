<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw;


use Bootgly\WPI\Modules\HTTP\Server\Response\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header\Cookies;


class Header extends Raw\Header
{
   // * Data
   public string $raw;


   public Cookies $Cookies;


   public function __construct ()
   {
      // * Data
      $this->raw = '';
      // Fields
      $this->preset = [
         'Server' => 'Bootgly',
         'Date' => true
      ];
      $this->prepared = [];
      $this->fields = [];

      // * Metadata
      $this->sent = false;
      // Fields
      $this->queued = [];
      $this->built = 0;

      // /
      $this->Cookies = new Cookies($this);
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         // * Config
         // ...

         // * Data
         // Fields
         case 'preset':
            return $this->preset;
         case 'prepared':
            return $this->prepared;
         case 'fields':
            return $this->fields;

         // * Metadata
         case 'sent':
            return $this->sent;
         // Fields
         case 'queued':
            return $this->queued;
         case 'built':
            return $this->built;

         default:
            return $this->get($name);
      }
   }
   public function __set (string $name, mixed $value): void
   {
      switch ($name) {
         // * Config
         // ...

         // * Data
         // Fields
         case 'preset':
            $this->preset = (array) $value;
            break;
         case 'prepared':
            break;
         // case 'fields':

         // * Metadata
         case 'sent':
            $this->sent = (bool) $value;
            break;
         // Fields
         case 'queued':
         case 'built':
            break;
      }
   }
   public function __isSet (string $name): bool
   {
      return isSet($this->fields[$name]);
   }

   public function reset (): void
   {
      // * Metadata
      // Fields
      $this->built = 0;
   }
   public function clean (): void
   {
      // * Data
      // Fields
      $this->prepared = [];
      $this->fields = [];
      // * Metadata
      // Fields
      $this->queued = [];
      $this->built = 0;
   }

   public function preset (string $name, ? string $value = null): void
   {
      if ($value) {
         $this->preset[$name] = $value;
      }
      else {
         unset($this->preset[$name]);
      }
   }
   /**
    * @param array<string> $fields
    */
   public function prepare (array $fields): void // @ Prepare to build
   {
      $this->prepared = $fields;
   }
   public function translate (string $field, int|float|string ...$values): string
   {
      switch ($field) {
         case 'Content-Range':
            // @ bytes Context
            // !
            $start = $values[0];
            $end = $values[1];
            $size = $values[2];

            if ($end !== '*') {
               $end = (int) $end;
               $size = (int) $size;
   
               if ($end > $size - 1) {
                  $end += 1;
               }
            }

            return "bytes {$start}-{$end}/{$size}";
         default:
            return '';
      }
   }

   public function get (string $name): string
   {
      return (string) (@$this->fields[$name] ?? @$this->fields[\strtolower($name)] ?? '');
   }

   public function set (string $field, string $value): bool
   {
      if ($field) {
         $this->fields[$field] = $value;

         return true;
      }

      return false;
   }
   public function append (string $field, string $value = '', ? string $separator = ', '): void
   {
      // TODO map separator (with const?)
      // Header that can have only value to append, only entire header, etc.

      if ( isSet($this->fields[$field]) ) {
         $this->fields[$field] .= $separator . $value;
      } else {
         $this->fields[$field] = $value;
      }
   }
   public function queue (string $field, string $value = ''): bool
   {
      if ($field) {
         $this->queued[] = "$field: $value";

         return true;
      }

      return false;
   }

   // TODO increase performance
   public function build (): true // @ raw
   {
      // ?
      if (\time() === $this->built) {
         return true;
      }

      // @
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
               'Date' => \gmdate('D, d M Y H:i:s \G\M\T'),
               default => ''
            };
         }

         $queued[] = "$name: $value";
      }

      $this->raw = \implode("\r\n", $queued);

      $this->built = \time();

      return true;
   }
}
