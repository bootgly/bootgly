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


use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Registration\Kinds;


/**
 * One route as the Router stored it — a row of `Router::$Routes`.
 *
 * A row is a record of a registration, not the current match (that is
 * `Route`): it is written once, when `route()` or `serve()` stores the route,
 * and never read while a request is dispatched.
 */
final readonly class Registration
{
   // * Config
   /**
    * The registered path with its group prefix applied — `/` for the root,
    * `/*` for the generic catch-all, params as written (`/users/:id<int>`).
    */
   public string $path;
   /**
    * The methods the dispatcher tests for this route, after group
    * inheritance; `null` when the route answers every method.
    *
    * @var null|array<int,string>
    */
   public null|array $methods;
   /**
    * The handler as it was registered — never the bound clone or the wrapper
    * the dispatcher holds; `null` for a `serve()` route, which has a `$body`
    * instead.
    *
    * @var null|object|array<int,mixed>|string
    */
   public null|object|array|string $handler;
   /**
    * The fixed body of a `serve()` route; `null` for a `route()` one.
    */
   public null|string $body;
   /**
    * The middlewares dispatch folds around this route, outermost first: every
    * `intercept()` in effect when it was registered, its group's, then its own.
    *
    * @var array<int,Middleware>
    */
   public array $Middlewares;
   /**
    * The path params in path order: `name`, the named `constraint` type
    * (`int`, `slug`, …) when one was written, and the `pattern` a segment
    * must match (`.+` for a `:name*` catch-all param).
    *
    * @var array<int,array{name:string,constraint:null|string,pattern:string}>
    */
   public array $parameters;
   /**
    * The route response cache options as registered (`['TTL' => seconds]`);
    * `null` without the `cache:` option. A route with middlewares is never
    * served from that cache, whatever is recorded here.
    *
    * @var null|array{TTL:int}
    */
   public null|array $cache;
   /**
    * Which lookup dispatches the route.
    */
   public Kinds $Kind;


   /**
    * @param null|array<int,string> $methods
    * @param null|object|array<int,mixed>|string $handler
    * @param array<int,Middleware> $Middlewares
    * @param array<int,array{name:string,constraint:null|string,pattern:string}> $parameters
    * @param null|array{TTL:int} $cache
    */
   public function __construct (
      string $path,
      null|array $methods,
      null|object|array|string $handler,
      null|string $body,
      array $Middlewares,
      array $parameters,
      null|array $cache,
      Kinds $Kind
   )
   {
      // * Config
      $this->path = $path;
      $this->methods = $methods;
      $this->handler = $handler;
      $this->body = $body;
      $this->Middlewares = $Middlewares;
      $this->parameters = $parameters;
      $this->cache = $cache;
      $this->Kind = $Kind;
   }
}
