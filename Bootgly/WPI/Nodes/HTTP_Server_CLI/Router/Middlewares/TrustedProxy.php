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
use function filter_var;
use function in_array;
use function strtolower;
use function trim;
use Closure;

use Bootgly\API\Workables\Server\Middleware;


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
         $candidate = trim($ips[0]);
         // ! Validate extracted IP before trusting it
         if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            $Request->address = $candidate; // @phpstan-ignore-line
         }
      }
      else {
         // @ Try X-Real-IP
         $realIp = $Request->Header->get('X-Real-IP'); // @phpstan-ignore-line
         if ($realIp !== null) {
            $candidate = trim($realIp);
            // ! Validate extracted IP before trusting it
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
               $Request->address = $candidate; // @phpstan-ignore-line
            }
         }
      }

      // @ Try X-Forwarded-Proto
      $proto = $Request->Header->get('X-Forwarded-Proto'); // @phpstan-ignore-line
      if ($proto !== null) {
         $candidate = strtolower(trim($proto));
         // ! Only accept valid HTTP schemes
         if (in_array($candidate, ['http', 'https'], true)) {
            $Request->scheme = $candidate; // @phpstan-ignore-line
         }
      }

      // :
      return $next($Request, $Response);
   }
}
