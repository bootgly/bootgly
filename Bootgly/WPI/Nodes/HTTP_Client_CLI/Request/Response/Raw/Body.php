<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Raw;


use function json_decode;
use function json_validate;


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw;

   // * Metadata
   public int $length;
   public int $downloaded;
   public bool $waiting;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->raw = '';

      // * Metadata
      $this->length = 0;
      $this->downloaded = 0;
      $this->waiting = false;
   }

   public function reset (): void
   {
      $this->raw = '';
      $this->length = 0;
      $this->downloaded = 0;
      $this->waiting = false;
   }

   /**
    * Decode the body content in the given format.
    *
    * @param string $type The decoding type: 'json'.
    * @param bool $associative Whether to decode JSON as associative array.
    *
    * @return mixed
    */
   public function decode (string $type = 'json', bool $associative = true): mixed
   {
      return match ($type) {
         'json' => ($this->raw !== '' && json_validate($this->raw))
            ? json_decode($this->raw, $associative)
            : null,
         default => $this->raw,
      };
   }
}
