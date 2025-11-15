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


use function strlen;
use function dechex;


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw = '';

   // * Metadata
   public protected(set) int $length {
      get {
         return $this->length = strlen($this->raw);
      }
      set (int $value) {
         $this->length = $value;
      }
   }
   // Encoded
   public protected(set) string $chunked {
      get {
         return $this->chunked = dechex(strlen($this->raw)) . "\r\n$this->raw\r\n";
      }
      set (string $value) {
         $this->chunked = $value;
      }
   }

   public function __construct ()
   {
      // * Data
      $this->raw = '';
   }
}
