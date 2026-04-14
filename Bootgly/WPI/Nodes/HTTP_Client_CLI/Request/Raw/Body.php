<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Raw;


use function http_build_query;
use function is_string;
use function json_encode;
use function strlen;


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw;
   public string|null $input;

   // * Metadata
   public int $length;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      $this->raw = '';
      $this->input = null;

      // * Metadata
      $this->length = 0;
   }

   /**
    * Encode body content in the given format.
    *
    * @param string|array<mixed,mixed> $data The body content data.
    * @param string $type The encoding type: 'raw', 'json', or 'form'.
    *
    * @return void
    */
   public function encode (string|array $data, string $type = 'raw'): void
   {
      $encoded = match ($type) {
         'json' => json_encode($data) ?: '',
         'form' => http_build_query((array) $data),
         default => is_string($data) ? $data : (json_encode($data) ?: ''),
      };

      $this->raw = $encoded;
      $this->input = $encoded;
      $this->length = strlen($encoded);
   }
}
