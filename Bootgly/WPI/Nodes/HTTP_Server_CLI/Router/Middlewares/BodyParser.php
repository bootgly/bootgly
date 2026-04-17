<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use function strlen;
use Closure;

use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


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
      // ! Push the BodyParser limit into the decoder-level gate so the
      //   server rejects oversized non-multipart bodies at decode time
      //   (before the full TCP payload is buffered). Without this, the
      //   global decoder cap (10 MB) would allow the full body to be
      //   accepted and only rejected later at middleware time — wasting
      //   bandwidth and memory. The push is idempotent and takes effect
      //   for subsequent requests (the current request is still validated
      //   by the Content-Length / body-length checks below).
      //   Note: $maxFileSize (multipart uploads) is intentionally NOT
      //   pushed — file upload limits are orthogonal to BodyParser's
      //   request body concern and have their own dedicated cap.
      if ($this->maxSize < Request::$maxBodySize) {
         Request::$maxBodySize = $this->maxSize;
      }
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
