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


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw = '';

   // * Metadata
   private int $length;
   // Encoded
   private string $chunked;


   public function __get (string $name)
   {
      switch ($name) {
         // * Metadata
         case 'length':
            return \strlen($this->raw);
         // Encoded
         case 'chunked':
            return \dechex(\strlen($this->raw)) . "\r\n$this->raw\r\n";

         default:
            return '';
      }
   }
}
