<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;


use Bootgly\WPI\Endpoints\Clients\Encoder as EncoderInterface;


abstract class Encoder implements EncoderInterface
{
   /**
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
    */
   abstract public static function encode (
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
