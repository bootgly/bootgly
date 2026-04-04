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


use function hash;
use function strlen;
use Closure;

use Bootgly\API\Server\Middleware;


class ETag implements Middleware
{
   // * Config
   public private(set) bool $weak;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param bool $weak Whether to generate weak ETags (default: true).
    */
   public function __construct (
      bool $weak = true
   )
   {
      // * Config
      $this->weak = $weak;
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // @ Pass through to handler first
      $Response = $next($Request, $Response);

      // ? Only compute ETag for non-empty bodies
      $body = $Response->Body->raw; // @phpstan-ignore-line
      if (strlen($body) === 0) {
         return $Response;
      }

      // @ Generate ETag
      $hash = hash('xxh3', $body);
      $etag = $this->weak ? 'W/"' . $hash . '"' : '"' . $hash . '"';
      $Response->Header->set('ETag', $etag); // @phpstan-ignore-line

      // ? Check If-None-Match header
      $ifNoneMatch = $Request->Header->get('If-None-Match'); // @phpstan-ignore-line
      if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
         return $Response(code: 304, body: ''); // @phpstan-ignore-line
      }

      // :
      return $Response;
   }
}
