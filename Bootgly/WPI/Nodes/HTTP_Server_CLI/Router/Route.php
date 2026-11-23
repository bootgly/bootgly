<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


use const Bootgly\WPI;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Route\Params;


class Route
{
   public const string START_PARAM = ':';

   // * Config
   #private string|null $name; // TODO: Use name for route groups and named routes
   public string $path;
   public Params $Params;

   // * Data
   /**
    * The middleware list the Router folded around the matched route — group
    * `intercept()` entries first, then the route's own `middlewares:` —
    * outermost first.
    *
    * Present only while a middleware-bearing dispatch runs; `Response::defer()`
    * clones the Route at that moment, so a deferred generation keeps the chain
    * its route was dispatched with, and the deferred loop walks it as the
    * error boundaries a Throwable from deferred work could no longer reach
    * through `$next`. Empty for middleware-free routes and outside a dispatch.
    *
    * @var array<Middleware>
    */
   public array $Middlewares = [];
   public string $base {
      get {
         $WPI = WPI;
         return $WPI->Request->base;
      }
      set (string $value) {
         $WPI = WPI;
         $WPI->Request->base = $value;
      }
   }


   public function __construct ()
   {
      $this->Params = new Params;

      // * Config
      #$this->name = null;

      // * Data
      $this->path = '';
   }

   public function __clone ()
   {
      // ! Route params are request state. A shallow clone would retain the
      //   worker Router's mutable Params object and preserve the C3 alias.
      $this->Params = clone $this->Params;
   }
}
