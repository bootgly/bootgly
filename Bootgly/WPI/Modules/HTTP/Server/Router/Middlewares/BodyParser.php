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


use function strlen;
use Closure;

use Bootgly\API\Server\Middleware;


class BodyParser implements Middleware
{
   // * Config
   public private(set) int $maxSize;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param int $maxSize Maximum request body size in bytes (default: 1 MB).
    */
   public function __construct (
      int $maxSize = 1_048_576
   )
   {
      // * Config
      $this->maxSize = $maxSize;
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // ? Validate Content-Length against max size
      $contentLength = $Request->Header->get('Content-Length'); // @phpstan-ignore-line
      if ($contentLength !== null && (int) $contentLength > $this->maxSize) {
         return $Response(code: 413, body: 'Payload Too Large'); // @phpstan-ignore-line
      }

      // ? Validate actual body size
      $body = $Request->input; // @phpstan-ignore-line
      if (strlen($body) > $this->maxSize) {
         return $Response(code: 413, body: 'Payload Too Large'); // @phpstan-ignore-line
      }

      // :
      return $next($Request, $Response);
   }
}
