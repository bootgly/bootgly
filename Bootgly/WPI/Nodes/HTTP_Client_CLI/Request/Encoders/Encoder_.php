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
      // ! Field names are matched at line starts: `X-Forwarded-Host:`,
      //   `Proxy-Connection:` or a value quoting `Host:` never stand in for the
      //   field itself (a redirect leg that dropped the caller's `Host` would
      //   otherwise go out with none)
      $lines = "\r\n{$headerRaw}";

      // Host
      if (stripos($lines, "\r\nHost:") === false) {
         $hostValue = ($port === 80 || $port === 443) ? $host : "{$host}:{$port}";
         $defaultHeaders .= "Host: {$hostValue}\r\n";
      }

      // Connection
      if (stripos($lines, "\r\nConnection:") === false) {
         $defaultHeaders .= "Connection: keep-alive\r\n";
      }

      // User-Agent
      if (stripos($lines, "\r\nUser-Agent:") === false) {
         $defaultHeaders .= "User-Agent: Bootgly/HTTP_Client_CLI\r\n";
      }

      // @ Build raw HTTP request
      $raw = "{$method} {$URI} {$protocol}\r\n{$defaultHeaders}{$headerRaw}\r\n{$body}";

      $length = strlen($raw);

      return $raw;
   }

}
