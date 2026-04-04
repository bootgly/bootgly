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

use Bootgly\API\Server\Middleware;


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
    * @param array<string> $origins Allowed origins.
    * @param array<string> $methods Allowed HTTP methods.
    * @param array<string> $headers Allowed request headers.
    * @param int $maxAge Preflight cache duration in seconds.
    * @param bool $credentials Whether to allow credentials.
    */
   public function __construct (
      array $origins = ['*'],
      array $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
      array $headers = ['Content-Type', 'Authorization'],
      int $maxAge = 86400,
      bool $credentials = false
   )
   {
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

      // ? Validate origin
      if ($origin !== null && $this->origins !== ['*']) {
         if (in_array($origin, $this->origins, true) === false) {
            return $Response(code: 403, body: 'Origin not allowed'); // @phpstan-ignore-line
         }
      }

      // @ Set CORS headers
      $allowOrigin = ($this->origins === ['*']) ? '*' : ($origin ?? '*');
      $Response->Header->set('Access-Control-Allow-Origin', $allowOrigin); // @phpstan-ignore-line
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
