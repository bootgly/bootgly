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


use function dechex;
use function strlen;


class Body
{
   // * Config
   // ...

   // * Data
   public string $raw = '';

   // * Metadata
   // @ `length` is a virtual property kept for back-compat; hot path in
   // Response/Raw::encode() bypasses this hook and calls strlen() directly.
   public protected(set) int $length {
      get => strlen($this->raw);
      set (int $value) {
         // @ Accepted for back-compat (e.g. HTTP Client decoded body length),
         // but value is recomputed from $raw on each read — effectively a no-op.
         // Kept to preserve the write API without breaking existing callers.
      }
   }
   // Encoded
   public protected(set) string $chunked {
      get => dechex(strlen($this->raw)) . "\r\n{$this->raw}\r\n";
      set (string $value) {
         // @ No-op setter (see $length note); chunked is derived from $raw.
      }
   }

   public function __construct ()
   {
      // * Data
      $this->raw = '';
   }
}
