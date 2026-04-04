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


use function explode;
use function in_array;
use function trim;
use Closure;

use Bootgly\API\Server\Middleware;


class TrustedProxy implements Middleware
{
   // * Config
   /** @var array<string> */
   public private(set) array $proxies;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param array<string> $proxies Trusted proxy IP addresses.
    */
   public function __construct (
      array $proxies = ['127.0.0.1', '::1']
   )
   {
      // * Config
      $this->proxies = $proxies;
   }

   public function process (object $Request, object $Response, Closure $next): object
   {
      // ? Only resolve if request comes from trusted proxy
      $clientIp = $Request->address; // @phpstan-ignore-line
      if (in_array($clientIp, $this->proxies, true) === false) {
         return $next($Request, $Response);
      }

      // @ Try X-Forwarded-For first
      $forwarded = $Request->Header->get('X-Forwarded-For'); // @phpstan-ignore-line
      if ($forwarded !== null) {
         $ips = explode(',', $forwarded);
         $realIp = trim($ips[0]);
         $Request->address = $realIp; // @phpstan-ignore-line
      }
      else {
         // @ Try X-Real-IP
         $realIp = $Request->Header->get('X-Real-IP'); // @phpstan-ignore-line
         if ($realIp !== null) {
            $Request->address = trim($realIp); // @phpstan-ignore-line
         }
      }

      // @ Try X-Forwarded-Proto
      $proto = $Request->Header->get('X-Forwarded-Proto'); // @phpstan-ignore-line
      if ($proto !== null) {
         $Request->scheme = trim($proto); // @phpstan-ignore-line
      }

      // :
      return $next($Request, $Response);
   }
}
