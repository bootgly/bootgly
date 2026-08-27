<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoders;


use function stripos;
use function strlen;

use InvalidArgumentException;

use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoder;


class Encoder_ extends Encoder
{
   /**
    * @param int<0, max>|null $length
    * @param-out int<0, max>|null $length
    * @throws InvalidArgumentException When request-line values are unsafe.
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
      // ? Public properties and redirect state can bypass Request::__invoke();
      //   the last textual-wire boundary therefore enforces the same rule.
      if (Request::check($method, $URI, $protocol) === false) {
         throw new InvalidArgumentException('Invalid HTTP client request-line.');
      }

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
