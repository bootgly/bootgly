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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing\Denial;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing\Gate;


/**
 * Authorization middleware executor.
 */
class Authorization implements Middleware
{
   // * Config
   // ...

   // * Data
   /**
    * Ordered strategy of authorization gates for the current route.
    */
   public private(set) Authorizing $Authorizing;
   /**
    * Optional custom forbidden response callback.
    *
    * @var null|Closure(object,object):object
    */
   public private(set) null|Closure $Fallback;

   // * Metadata
   // ...


   /**
    * Create an authorization middleware.
    *
    * @param null|Closure(object,object):object $Fallback
    *
    * @throws InvalidArgumentException When no gate is configured.
    */
   public function __construct (
      Authorizing $Authorizing,
      null|Closure $Fallback = null
   )
   {
      if (count($Authorizing->Gates) === 0) {
         throw new InvalidArgumentException('Authorization middleware requires at least one gate.');
      }

      // * Data
      $this->Authorizing = $Authorizing;
      $this->Fallback = $Fallback;
   }

   /**
    * Run the middleware pipeline with authorization enforcement.
    *
    * @param Closure(object,object):object $next
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      // @ Require every configured gate to pass.
      foreach ($this->Authorizing->Gates as $Gate) {
         if ($Gate->authorize($Request) === false) {
            return $this->deny($Request, $Response, $Gate);
         }
      }

      // : Authorized.
      return $next($Request, $Response);
   }

   /**
    * Resolve the forbidden response for a failed authorization attempt.
    */
   private function deny (object $Request, object $Response, Gate $Gate): object
   {
      // ?: Custom fallback.
      if ($this->Fallback !== null) {
         Denial::mark($Response);

         $Result = ($this->Fallback)($Request, $Response);
         return Denial::mark($Result);
      }

      // : Gate-specific denial.
      return $Gate->deny($Response);
   }
}
