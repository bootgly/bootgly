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


use function implode;
use function in_array;
use Closure;

use Bootgly\API\Workables\Server\Middleware;


class CORS implements Middleware
{
   // * Config
   /** @var array<string> */
   public private(set) array $origins;
   /** @var array<string> */
   public private(set) array $methods;
   /** @var array<string> */
   public private(set) array $headers;
   public private(set) int $maxAge;
   public private(set) bool $credentials;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param array<string> $origins Allowed origins. Defaults to an empty
    *        allowlist (audit F-8): restrictive by default — every cross-origin
    *        request is rejected until origins are explicitly configured. Pass
    *        `['*']` to opt in to a wildcard (origin-independent) policy.
    * @param array<string> $methods Allowed HTTP methods.
    * @param array<string> $headers Allowed request headers.
    * @param int $maxAge Preflight cache duration in seconds.
    * @param bool $credentials Whether to allow credentials.
    *        NOTE: Cannot be true when $origins is ['*'] — the CORS spec forbids
    *        wildcard origin with credentials. $credentials will be forced to false
    *        in that case.
    */
   public function __construct (
      array $origins = [],
      array $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
      array $headers = ['Content-Type', 'Authorization'],
      int $maxAge = 86400,
      bool $credentials = false
   )
   {
      // ! Wildcard origin + credentials is forbidden by the CORS spec (Fetch §3.2)
      if ($origins === ['*'] && $credentials === true) {
         $credentials = false;
      }

      // * Config
      $this->origins = $origins;
      $this->methods = $methods;
      $this->headers = $headers;
      $this->maxAge = $maxAge;
      $this->credentials = $credentials;
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // ! Build CORS headers
      $origin = $Request->Header->get('Origin'); // @phpstan-ignore-line

      $wildcard = ($this->origins === ['*']);

      // ? Validate origin against the allowlist (skipped only for wildcard).
      //   With the restrictive default (empty allowlist, audit F-8) a
      //   cross-origin request is rejected unless an origin is explicitly
      //   configured.
      if ($origin !== null && $wildcard === false) {
         if (in_array($origin, $this->origins, true) === false) {
            return $Response(code: 403, body: 'Origin not allowed'); // @phpstan-ignore-line
         }
      }

      // @ Access-Control-Allow-Origin (audit F-8):
      //   - wildcard config         → constant `*` (origin-independent).
      //   - allowlist + valid Origin → reflect it AND emit `Vary: Origin`, so a
      //     shared cache (CDN/proxy) can never serve origin A's response (incl.
      //     `Allow-Credentials: true`) to origin B.
      //   - allowlist + no Origin   → not a CORS request: emit nothing, never
      //     fall back to `*`.
      if ($wildcard) {
         $Response->Header->set('Access-Control-Allow-Origin', '*'); // @phpstan-ignore-line
      }
      else if ($origin !== null) {
         $Response->Header->set('Access-Control-Allow-Origin', $origin); // @phpstan-ignore-line
         $Response->Header->append('Vary', 'Origin'); // @phpstan-ignore-line
      }

      $Response->Header->set('Access-Control-Allow-Methods', implode(', ', $this->methods)); // @phpstan-ignore-line
      $Response->Header->set('Access-Control-Allow-Headers', implode(', ', $this->headers)); // @phpstan-ignore-line
      $Response->Header->set('Access-Control-Max-Age', (string) $this->maxAge); // @phpstan-ignore-line

      if ($this->credentials) {
         $Response->Header->set('Access-Control-Allow-Credentials', 'true'); // @phpstan-ignore-line
      }

      // ? Preflight — short-circuit OPTIONS requests
      if ($Request->method === 'OPTIONS') { // @phpstan-ignore-line
         return $Response(code: 204); // @phpstan-ignore-line
      }

      // :
      return $next($Request, $Response);
   }
}
