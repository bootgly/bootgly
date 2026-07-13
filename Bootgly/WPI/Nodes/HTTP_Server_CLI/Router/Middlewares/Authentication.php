<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use function count;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;
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
      * Redirecting fallbacks are the one exception: a callback result that is
      * already a redirect (3xx + `Location`) is returned untouched, so
      * browser/session flows can send guests to a login page.
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
         // ?: Redirecting fallbacks (e.g. guest → login page) keep their 3xx
         if ($this->detect($Result)) {
            return $Result;
         }

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

   /**
    * Detect a redirecting fallback result (3xx status + `Location` header).
    */
   private function detect (object $Result): bool
   {
      // ! Status code.
      $code = $Result->code ?? null;
      // ?
      if (is_int($code) === false || $code < 300 || $code > 399) {
         return false;
      }

      // ! Location header.
      $Header = $Result->Header ?? null;
      // ?
      if (is_object($Header) === false || method_exists($Header, 'get') === false) {
         return false;
      }

      // :
      $location = $Header->get('Location');

      return is_string($location) && $location !== '';
   }
}