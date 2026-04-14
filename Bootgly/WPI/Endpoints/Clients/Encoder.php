<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Clients;


interface Encoder
{
   /**
    * Encode an HTTP Request into a raw wire string.
    * If $body is omitted (or empty), only the request-line and headers are encoded.
    *
    * @param string $method HTTP method.
    * @param string $URI Request URI.
    * @param string $protocol HTTP protocol version.
    * @param string $headerRaw Raw header string.
    * @param string $body Request body.
    * @param string $host Target host.
    * @param int $port Target port.
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
    *
    * @return string
    */
   public static function encode (
      string $method,
      string $URI,
      string $protocol,
      string $headerRaw,
      string $body = '',
      string $host = '',
      int $port = 80,
      null|int &$length = null
   ): string;
}
