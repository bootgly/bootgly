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


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw;

   // * Metadata
   public int $length;
   public static array $mimes; // @ 'html' => 'text/html'
   private string $chunked;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->raw = '';

      // * Metadata
      $this->length = 0;
      // mimes
   }

   public function __get (string $name) : string
   {
      switch ($name) {
         case 'chunked': // TODO move to property hook
            $this->chunked = \dechex($this->length) . "\r\n$this->raw\r\n";
            return $this->chunked;
         default:
            return '';
      }
   }
}
