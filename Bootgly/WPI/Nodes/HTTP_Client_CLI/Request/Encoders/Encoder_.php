<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoders;


use function stripos;
use function strlen;

use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoder;


class Encoder_ extends Encoder
{
   /**
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
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
   ): string
   {
      // @ Add default headers if not present
      $defaultHeaders = '';

      // Host
      if (stripos($headerRaw, 'Host:') === false) {
         $hostValue = ($port === 80 || $port === 443) ? $host : "{$host}:{$port}";
         $defaultHeaders .= "Host: {$hostValue}\r\n";
      }

      // Connection
      if (stripos($headerRaw, 'Connection:') === false) {
         $defaultHeaders .= "Connection: keep-alive\r\n";
      }

      // User-Agent
      if (stripos($headerRaw, 'User-Agent:') === false) {
         $defaultHeaders .= "User-Agent: Bootgly/HTTP_Client_CLI\r\n";
      }

      // @ Build raw HTTP request
      $raw = "{$method} {$URI} {$protocol}\r\n{$defaultHeaders}{$headerRaw}\r\n{$body}";

      $length = strlen($raw);

      return $raw;
   }

}
