<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares;


use function bin2hex;
use function random_bytes;
use Closure;

use Bootgly\API\Server\Middleware;


class RequestId implements Middleware
{
   // * Config
   public private(set) string $header;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param string $header The header name for the request ID (default: X-Request-Id).
    */
   public function __construct (
      string $header = 'X-Request-Id'
   )
   {
      // * Config
      $this->header = $header;
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // ! Generate or use existing request ID
      $requestId = $Request->Header->get($this->header); // @phpstan-ignore-line

      if ($requestId === null || $requestId === '') {
         // @ Generate UUID v4-like ID
         $data = random_bytes(16);
         $data[6] = \chr(\ord($data[6]) & 0x0f | 0x40);
         $data[8] = \chr(\ord($data[8]) & 0x3f | 0x80);
         $requestId = bin2hex($data[0] . $data[1] . $data[2] . $data[3]) . '-'
            . bin2hex($data[4] . $data[5]) . '-'
            . bin2hex($data[6] . $data[7]) . '-'
            . bin2hex($data[8] . $data[9]) . '-'
            . bin2hex($data[10] . $data[11] . $data[12] . $data[13] . $data[14] . $data[15]);
      }

      // @ Set request ID on response
      $Response->Header->set($this->header, $requestId); // @phpstan-ignore-line

      // :
      return $next($Request, $Response);
   }
}
