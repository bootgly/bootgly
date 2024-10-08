<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Response\Raw;


use function strlen;
use function dechex;


abstract class Body
{
   // * Config
   // ...

   // * Data
   public string $raw = '';

   // * Metadata
   protected int $length;
   // Encoded
   protected string $chunked;


   public function __get (string $name): mixed
   {
      switch ($name) {
         // * Metadata
         case 'length':
            return $this->length ??= strlen($this->raw);
         // Encoded
         case 'chunked':
            return $this->chunked ??= dechex(strlen($this->raw)) . "\r\n$this->raw\r\n";

         default:
            return '';
      }
   }
}
