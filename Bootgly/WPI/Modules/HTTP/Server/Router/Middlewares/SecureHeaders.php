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


use Closure;

use Bootgly\API\Server\Middleware;


class SecureHeaders implements Middleware
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
            'max-age=' . $this->hstsMaxAge . '; includeSubDomains'
         );
      }

      // :
      return $Response;
   }
}
