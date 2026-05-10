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


use function count;
use Closure;
use InvalidArgumentException;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Challenge;


/**
 * Authentication middleware executor.
 *
 * The middleware delegates all mechanism-specific work to an `Authenticating`
 * guard strategy. It succeeds when any configured guard authenticates the
 * request and fails closed with either a custom fallback or the first guard's
 * challenge response.
 */
class Authentication implements Middleware
{
   // * Config
   // ...

   // * Data
   /**
    * Ordered strategy of authentication guards for the current route.
    */
   public private(set) Authenticating $Authenticating;
   /**
    * Optional custom unauthorized response callback.
      *
      * The middleware normalizes the response status to `401 Unauthorized`
      * before and after the callback, so fallback formatters can customize the
      * body/headers without accidentally returning a successful status.
    *
      * @var null|Closure(object,object):object
    */
   public private(set) null|Closure $Fallback;

   // * Metadata
   // ...


   /**
      * Create an authentication middleware.
      *
      * At least one guard is required. Empty strategies fail during middleware
      * creation so protected routes cannot be misconfigured silently.
      *
      * @param null|Closure(object,object):object $Fallback
      *
      * @throws InvalidArgumentException When no guard is configured.
    */
   public function __construct (
      Authenticating $Authenticating,
      null|Closure $Fallback = null
   )
   {
      if (count($Authenticating->Guards) === 0) {
         throw new InvalidArgumentException('Authentication middleware requires at least one guard.');
      }

      // * Data
      $this->Authenticating = $Authenticating;
      $this->Fallback = $Fallback;
   }

   /**
    * Run the middleware pipeline with authentication enforcement.
    *
      * @param Closure(object,object):object $next
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      // @ Try guards in configured order.
      foreach ($this->Authenticating->Guards as $Guard) {
         if ($Guard->authenticate($Request)) {
            return $next($Request, $Response);
         }
      }

      // : Unauthorized.
      return $this->deny($Request, $Response);
   }

   /**
    * Resolve the unauthorized response for a failed authentication attempt.
    */
   private function deny (object $Request, object $Response): object
   {
      // ?: Custom fallback.
      if ($this->Fallback !== null) {
         Challenge::mark($Response);

         $Result = ($this->Fallback)($Request, $Response);
         return Challenge::mark($Result);
      }

      // @ Use the first configured guard challenge as the route challenge.
      $Guard = $this->Authenticating->Guards[0] ?? null;
      if ($Guard !== null) {
         return $Guard->challenge($Response);
      }

      // : Generic unauthorized fallback.
      return Challenge::reject($Response);
   }
}