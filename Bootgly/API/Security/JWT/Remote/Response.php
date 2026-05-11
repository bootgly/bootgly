<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT\Remote;


use InvalidArgumentException;


/**
 * Remote JWKS HTTP response snapshot.
 */
class Response
{
   // * Data
   public private(set) int $status;
   public private(set) string $body;


   /**
    * Create a remote JWKS response snapshot.
    */
   public function __construct (int $status, string $body)
   {
      if ($status < 100 || $status > 599) {
         throw new InvalidArgumentException('JWKS response status must be an HTTP status code.');
      }

      // * Data
      $this->status = $status;
      $this->body = $body;
   }
}
