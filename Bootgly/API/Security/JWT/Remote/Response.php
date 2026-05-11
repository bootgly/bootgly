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


use function is_string;
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
    * HTTP response headers.
    *
    * @var array<int|string,string>
    */
   public private(set) array $headers;


   /**
    * Create a remote JWKS response snapshot.
      *
      * @param array<int|string,mixed> $headers
    */
   public function __construct (int $status, string $body, array $headers = [])
   {
      if ($status < 100 || $status > 599) {
         throw new InvalidArgumentException('JWKS response status must be an HTTP status code.');
      }
      $Headers = [];
      foreach ($headers as $name => $header) {
         if (is_string($header) === false) {
            throw new InvalidArgumentException('JWKS response headers must be strings.');
         }

         $Headers[$name] = $header;
      }

      // * Data
      $this->status = $status;
      $this->body = $body;
      $this->headers = $Headers;
   }
}
