<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use Closure;

use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Sealing;


class SecureHeaders implements Middleware, Sealing
{
   // * Config
   public private(set) string $contentSecurityPolicy;
   public private(set) bool $hsts;
   public private(set) int $hstsMaxAge;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param string $contentSecurityPolicy CSP header value.
    * @param bool $hsts Whether to enable HSTS.
    * @param int $hstsMaxAge HSTS max-age in seconds (default: 1 year).
    */
   public function __construct (
      string $contentSecurityPolicy = "default-src 'self'",
      bool $hsts = true,
      int $hstsMaxAge = 31536000
   )
   {
      // * Config
      $this->contentSecurityPolicy = $contentSecurityPolicy;
      $this->hsts = $hsts;
      $this->hstsMaxAge = $hstsMaxAge;
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // @ Pass through to handler first
      $Response = $next($Request, $Response);

      // @ One pass serves both cycles — a deferred generation runs it at
      //   settlement (`seal()`), against the Response chosen for the wire
      $this->stamp($Response);

      // :
      return $Response;
   }

   /**
    * Set the security headers on a deferred Response about to be serialized
    * — the post-`$next()` half `process()` could not apply to the deferred
    * copy, on every answer chosen for the wire (the work's, an error
    * boundary's or the Catcher's).
    *
    * @param Request $Request The generation's captured Request snapshot.
    * @param Response $Response The Response chosen for the wire.
    */
   public function seal (Request $Request, Response $Response): void
   {
      $this->stamp($Response);
   }

   /**
    * Set the security headers — the shared half of both cycles, typed loosely
    * because the synchronous unit tests hand doubles.
    *
    * @param Response $Response
    */
   private function stamp (object $Response): void
   {
      // @ Set security headers
      $Response->Header->set('X-Content-Type-Options', 'nosniff'); // @phpstan-ignore-line
      $Response->Header->set('X-Frame-Options', 'SAMEORIGIN'); // @phpstan-ignore-line
      $Response->Header->set('X-XSS-Protection', '1; mode=block'); // @phpstan-ignore-line
      $Response->Header->set('Referrer-Policy', 'strict-origin-when-cross-origin'); // @phpstan-ignore-line
      $Response->Header->set('Content-Security-Policy', $this->contentSecurityPolicy); // @phpstan-ignore-line
      $Response->Header->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()'); // @phpstan-ignore-line

      if ($this->hsts) {
         $Response->Header->set( // @phpstan-ignore-line
            'Strict-Transport-Security',
            "max-age={$this->hstsMaxAge}; includeSubDomains"
         );
      }
   }
}
