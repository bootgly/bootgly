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
      // ! No mutation of Request::$maxBodySize here.
      //   Earlier revisions pushed $this->maxSize into the global
      //   Request::$maxBodySize to enable decoder-level rejection of
      //   oversized bodies. That mutation was PERMANENT and
      //   WORKER-WIDE, so a single BodyParser(maxSize: N) on one
      //   restrictive route would starve every *other* route on the
      //   same worker. Enforcement now happens strictly at middleware
      //   time via the Content-Length and body-length checks below,
      //   which are already route-local (scoped to $this->maxSize).
      //   For server-wide decoder caps use Request::$maxBodySize
      //   directly in application bootstrap — not per-route middleware.

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
