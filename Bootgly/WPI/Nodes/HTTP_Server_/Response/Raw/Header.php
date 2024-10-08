<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_\Response\Raw;


use function implode;
use function array_merge;
use function strtolower;
use function header;
use function apache_response_headers;
use function headers_sent;

use Bootgly\WPI\Modules\HTTP\Server\Response\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_\Response\Raw\Header\Cookies;


class Header extends Raw\Header
{
   public Cookies $Cookies;


   public function __construct ()
   {
      // * Config
      $this->preset = [];
      $this->prepared = [];

      // * Data
      $this->fields = [];

      // * Metadata
      $this->queued = [];
      $this->raw = '';
      $this->sent = false;

      $this->Cookies = new Cookies($this);
   }
   public function __get (string $name): mixed
   {
      switch ($name) {
         // * Config
         case 'preset':
         case 'prepared':
            return $this->$name;
         // * Data
         case 'fields':
         case 'headers':
            $this->fields = apache_response_headers();

            return $this->fields;
         // * Metadata
         case 'queued':
            return $this->queued;
         case 'raw':
            if ($this->raw) {
               return $this->raw;
            }

            $this->build();

            return $this->raw;

         case 'sent':
            return headers_sent();

         default:
            return $this->get($name);
      }
   }
   public function __set (string $name, mixed $value)
   {
      switch ($name) {
         // * Config
         case 'preset':
            $this->preset = (array) $value;

            break;
         case 'prepared':
         // * Data
         // case 'fields':
         // * Metadata
         case 'queued':
            break;
         // *
         default:
            $this->$name = $value;
      }
   }
   public function __isSet (string $name): bool
   {
      return isSet($this->fields[$name]);
   }

   public function clean (): void
   {
      $this->fields = [];
   }

   /**
    * Prepare headers
    *
    * @param array<string> $fields
    */
   public function prepare (array $fields): void
   {
      $this->prepared = $fields;
   }

   public function get (string $name): string
   {
      return (string) (@$this->fields[$name] ?? @$this->fields[strtolower($name)] ?? '');
   }

   public function set (string $field, string $value): bool
   {
      $this->fields[$field] = $value;

      $header = <<<HEADER
      $field: $value
      HEADER;

      header($header, true);

      return true;
   }
   public function append (
      string $field, string $value = '', ? string $separator = ', '
   ): void  // TODO refactor
   {
      // TODO map separator (with const?) Header that can have only value to append, only entire header, etc.

      if ( isSet($this->fields[$field]) ) {
         $this->fields[$field] .= $separator . $value;
      }
      else {
         $this->fields[$field] = $value;
      }

      $header = <<<HEADER
      $field: $value
      HEADER;

      header($header, false);
   }
   public function queue (string $field, string $value = ''): bool
   {
      $this->queued[] = "$field: $value";

      return true;
   }

   // TODO increase performance
   public function build (bool $send = false): bool
   {
      // ?
      if ( empty($this->fields) && empty($this->prepared) ) {
         return false;
      }

      // @ Set / merge headers with prepared fields
      if ( empty($this->fields) && ! empty($this->prepared) ) {
         $this->fields = $this->prepared;
      }
      else {
         $this->fields = array_merge($this->fields, $this->prepared);
      }

      // @ Set default headers
      $this->fields['Content-Type'] ??= 'text/html; charset=UTF-8';

      // @ Build
      $queued = &$this->queued;
      // Preset Fields
      foreach ($this->preset as $name => $value) {
         $queued[] = "$name: $value";
      }
      // Fields
      foreach ($this->fields as $name => $value) {
         $queued[] = "$name: $value";
      }

      $this->raw = implode("\r\n", $queued);

      if ($send === true) {
         foreach ($queued as $header) {
            header($header, false);
         }
      }

      return true;
   }

   public function send (): void
   {
      $queued = $this->queued;

      foreach ($queued as $header) {
         header($header, false);
      }
   }
}
