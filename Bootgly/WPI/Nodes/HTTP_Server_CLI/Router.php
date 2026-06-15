<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use function array_keys;
use function count;
use function explode;
use function extract;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_quote;
use function rtrim;
use function str_ends_with;
use function strpos;
use function substr;
use function substr_count;
use function trim;
use Closure;
use Generator;
use InvalidArgumentException;

use const Bootgly\WPI;
use Bootgly\ABI\IO\FS\File;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Route;


class Router
{
   // * Config
   // # Param constraint types → regex patterns (compile-time only)
   private const array PARAM_CONSTRAINTS = [
      'int'      => '[0-9]+',
      'alpha'    => '[a-zA-Z]+',
      'alphanum' => '[a-zA-Z0-9]+',
      'slug'     => '[a-zA-Z0-9_-]+',
      'uuid'     => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
   ];

   // * Data
   // # Status
   protected bool $active;
   // # Middleware
   /** @var array<Middleware> */
   private array $Middlewares = [];
   // # Cache
   /** @var array<string,array<string,Closure>> */
   private array $staticCache = [];
   /**
    * Fast flag — set true iff at least one method-agnostic static route exists.
    * Lets `resolve()` skip the `staticCache[''][$url]` lookup entirely on the
    * hot path when no agnostic routes were registered (common case).
    */
   private bool $hasAgnosticStatic = false;
   /** @var array<string,array<int,array<array{type:string,pattern:string,methods:array<string>,paramNames:array<string>,fixedSegments:array<int,string>,paramIndices:array<int>,duplicateParams:array<string,bool>,dispatcher:Closure}>>> */
   private array $dynamicCache = [];
   /** Fast flag — set true iff any dynamic route was registered. */
   private bool $hasDynamic = false;
   private null|Closure $catchAllCache = null;
   /** @var array<array{prefix:string,handler:callable,methods:array<string>,Middlewares:array<Middleware>}> */
   private array $pendingGroups = [];
   private null|string $groupPrefix = null;

   /**
    * Whether the route cache has been warmed (all routes registered).
    *
    * Plain public property (no getter hook) — read every request on the hot path.
    * Written internally when warming finishes.
    */
   public bool $cached = false;

   // * Metadata
   public Route $Route;


   public function __construct ()
   {
      // * Data
      $this->active = true;

      // * Metadata
      $this->Route = new Route;
   }

   /**
    * Load the router from its folder and return the request handler.
    *
    * Reads the router index (`router.index.php`) inside `$path` — a manifest of route set
    * names. Each name resolves to `routes/<Name>.php`, a generator-closure
    * `(Request, Response, Router): Generator`. A single set is returned directly; multiple
    * sets are composed (`yield from` each) into one handler. Pass the result to
    * `->on(Events::RequestReceived, ...)`.
    *
    * The folder is the router home — reserved for future `router.config.php` defaults.
    *
    * @param string $path The router folder path.
    */
   public function load (string $path): Closure
   {
      // ? Normalize router folder path
      $path = rtrim($path, '/');

      // @ (future) router.config.php — customize Router defaults
      //   $Config = new File("$path/router.config.php"); apply if present.

      // @ Read router index (manifest of route set names)
      $Index = new File("$path/router.index.php");
      // ? Index must exist
      if ($Index->exists === false) {
         throw new InvalidArgumentException("Router index not found: $path/router.index.php");
      }
      $names = require $Index->file;
      // ? Index must return a non-empty list of route set names
      if (is_array($names) === false || $names === []) {
         throw new InvalidArgumentException('router.index.php must return a non-empty array of route set names.');
      }

      // @ Resolve each name to its route set closure
      $Sets = [];
      foreach ($names as $name) {
         // ? Each name must be a string
         if (is_string($name) === false) {
            throw new InvalidArgumentException('router.index.php must return an array of route set name strings.');
         }

         $Set = new File("$path/routes/$name.php");
         // ? Set file must exist
         if ($Set->exists === false) {
            throw new InvalidArgumentException("Route set not found: $path/routes/$name.php");
         }
         $Handler = require $Set->file;
         // ? Set must be a generator-closure
         if ($Handler instanceof Closure === false) {
            throw new InvalidArgumentException("Route set '$name' must return a Closure (Request, Response, Router): Generator.");
         }
         $Sets[] = $Handler;
      }

      // ? Single set — return it directly (no compose overhead)
      if (count($Sets) === 1) {
         return $Sets[0];
      }

      // : Compose multiple sets into one handler
      return static function (Request $Request, Response $Response, Router $Router) use ($Sets): Generator {
         foreach ($Sets as $Set) {
            yield from $Set($Request, $Response, $Router);
         }
      };
   }

   public function pause (): void
   {
      $this->active = false;
   }
   public function continue (): void
   {
      $this->active = true;
   }

   // # Middleware
   /**
    * Register Middlewares for the current route group.
    */
   public function intercept (Middleware ...$Middlewares): void
   {
      foreach ($Middlewares as $Middleware) {
         $this->Middlewares[] = $Middleware;
      }
   }
   /**
    * Build the dispatcher Closure for a route (built once at cache time).
    *
    * - No-middleware case: returns the bound handler directly (zero wrapper frame).
    * - With middlewares: returns the folded onion pipeline closure.
    *
    * The post-call `WPI->Response` swap (only needed when a handler returns a
    * NEW Response instance — rare in practice) is handled inline by `resolve()`.
    *
    * @param array<Middleware> $Middlewares
    */
   private function dispatch (callable $handler, array $Middlewares): Closure
   {
      // ? No middleware — return handler directly, no wrapper frame
      if ($Middlewares === []) {
         return $handler instanceof Closure
            ? $handler
            : Closure::fromCallable($handler);
      }

      // @ Innermost: handler wrapper that normalizes return to Response
      $invokable = function (object $Request, object $Response) use ($handler): object {
         $Result = $handler($Request, $Response);
         return $Result instanceof Response ? $Result : $Response;
      };
      // @ Fold right over Middlewares
      for ($i = count($Middlewares) - 1; $i >= 0; $i--) {
         $Middleware = $Middlewares[$i];
         $next = $invokable;
         $invokable = function (object $Request, object $Response) use ($Middleware, $next): object {
            return $Middleware->process($Request, $Response, $next);
         };
      }

      return $invokable;
   }

   // # Cache
   /**
    * Flatten pending group routes into static/dynamic cache.
    * Calls each group handler to discover nested routes and caches them
    * with fully-qualified paths (e.g. /admin/dashboard).
    */
   private function flatten (): void
   {
      while ($this->pendingGroups !== []) {
         $groups = $this->pendingGroups;
         $this->pendingGroups = [];

         foreach ($groups as $group) {
            // ! Save state
            $SavedMiddlewares = $this->Middlewares;
            $savedPrefix = $this->groupPrefix;

            // ! Set group context
            $this->Middlewares = $group['Middlewares'];
            $this->groupPrefix = $group['prefix'];

            // @ Call group handler to register nested routes
            $handler = $group['handler'];
            if ($handler instanceof Closure) {
               $handler = $handler->bindTo($this->Route, $this->Route) ?? $handler;
            }
            /** @var Generator|mixed $NestedRoutes */
            $NestedRoutes = $handler();

            // @ Iterate Generator: each yield calls route() → cache()
            //   (handlers that register via direct $Router->route() calls — without
            //    yielding — have already executed by the time $handler() returned)
            if ($NestedRoutes instanceof Generator) {
               foreach ($NestedRoutes as $_) {
                  // intentional drain
               }
            }

            // ! Restore state
            $this->Middlewares = $SavedMiddlewares;
            $this->groupPrefix = $savedPrefix;
         }
      }
   }
   private function complete (mixed $Result, Response $Response): Response
   {
      if ($Result instanceof Response) {
         if ($Result !== $Response) {
            $WPI = WPI;
            $WPI->Response = $Result;
            return $Result;
         }

         return $Response;
      }

      return $Response;
   }
   /**
    * Resolve the current request from the route cache.
    *
    * @return Response|false|null null = cache miss (no catch-all), false = router paused, Response = matched
    */
   public function resolve (): Response|false|null
   {
      if ($this->active === false) {
         return false;
      }

      $WPI = WPI;
      $Request = $WPI->Request;
      $URI = $Request->URI;
      $method = $Request->method;
      $Response = $WPI->Response;

      // 1. Static route lookup — O(1)
      if ($URI === '/') {
         $Dispatcher = $this->staticCache[$method][''] ?? null;
         if ($Dispatcher === null) {
            $Dispatcher = $this->staticCache[''][''] ?? null;
         }
         if ($Dispatcher !== null) {
            $Result = $Dispatcher($Request, $Response);
            if ($Result === $Response) {
               return $Response;
            }
            if ($Result instanceof Response) {
               $WPI->Response = $Result;
               return $Result;
            }
            return $Response;
         }

         $url = '';
      }
      else {
         $url = $Request->URL;
      }

      $Dispatcher = $this->staticCache[$method][$url] ?? null;
      if ($Dispatcher === null) {
         $Dispatcher = $this->staticCache[''][$url] ?? null;
      }

      if ($Dispatcher !== null) {
         $Result = $Dispatcher($Request, $Response);
         if ($Result === $Response) {
            return $Response;
         }

         if ($Result instanceof Response) {
            $WPI->Response = $Result;
            return $Result;
         }

         return $Response;
      }

      // 2. Dynamic route lookup — skip entirely when no dynamic routes registered
      if ($this->hasDynamic) {
         $slashPos = strpos($url, '/', 1);
         $firstSegment = $slashPos !== false ? substr($url, 1, $slashPos - 1) : substr($url, 1);
         $segCount = substr_count($url, '/');
         /** @var array<string>|null $segments */
         $segments = null;

         for ($bucketIdx = 0; $bucketIdx < 2; $bucketIdx++) {
            $segBucket = $bucketIdx === 0
               ? ($this->dynamicCache[$firstSegment] ?? null)
               : ($this->dynamicCache[''] ?? null);

            if ($segBucket === null) {
               continue;
            }

            // @ Check exact segment-count bucket, then catch-all bucket (key 0)
            for ($scIdx = 0; $scIdx < 2; $scIdx++) {
               $bucket = $scIdx === 0
                  ? ($segBucket[$segCount] ?? null)
                  : ($segBucket[0] ?? null);

               if ($bucket === null) {
                  continue;
               }

               foreach ($bucket as $entry) {
                  // @ Method check
                  if ($entry['methods'] !== [] && !in_array($method, $entry['methods'], strict: true)) {
                     continue;
                  }

                  if ($entry['type'] === 'simple') {
                     // @ Segment-based matching (no regex)
                     if ($entry['fixedSegments'] !== []) {
                        if ($segments === null) {
                           $segments = explode('/', $url);
                        }

                        $match = true;
                        foreach ($entry['fixedSegments'] as $pos => $expected) {
                           if ($segments[$pos] !== $expected) {
                              $match = false;
                              break;
                           }
                        }

                        if (!$match) {
                           continue;
                        }
                     }

                     // @ Direct param extraction via batch set
                     $Route = $this->Route;
                     $Route->path = $url;
                     if ($segments === null) {
                        $segments = explode('/', $url);
                     }

                     /** @var array<string,string> $pv */
                     $pv = [];
                     foreach ($entry['paramIndices'] as $i => $segIdx) {
                        $pv[$entry['paramNames'][$i]] = $segments[$segIdx];
                     }

                     $Route->Params->set($pv);
                  }
                  else {
                     // @ Complex route: regex matching
                     if (!preg_match($entry['pattern'], $url, $matches)) {
                        continue;
                     }

                     $Route = $this->Route;
                     $Route->path = $url;
                     $duplicateParams = $entry['duplicateParams'];
                     foreach ($entry['paramNames'] as $i => $paramName) {
                        if (isset($duplicateParams[$paramName])) {
                           $current = $Route->Params->$paramName;
                           if ($current === null || !is_array($current)) {
                              $Route->Params->$paramName = [$matches[$i + 1]];
                           }
                           else {
                              $current[] = $matches[$i + 1];
                              $Route->Params->$paramName = $current;
                           }
                        }
                        else {
                           $Route->Params->$paramName = $matches[$i + 1];
                        }
                     }
                  }

                  return $this->complete(($entry['dispatcher'])($Request, $Response), $Response);
               }
            }
         }
      }

      // 3. Catch-all fallback
      if ($this->catchAllCache !== null) {
         // ! Security: DO NOT promote attacker-controlled miss URLs into
         //   `staticCache['']` — `catchAllCache` is already O(1), so
         //   promotion adds no perf benefit while allowing an
         //   unauthenticated client to grow the static cache by one
         //   entry per unique miss URL (bounded memory DoS until the
         //   worker is recycled). Serve directly from `catchAllCache`.
         return $this->complete(($this->catchAllCache)($Request, $Response), $Response);
      }

      return null;
   }
   /**
    * Populate route cache tables (called once per route definition).
    *
    * @param null|string|array<string> $methods
    * @param array<Middleware> $Middlewares
    */
   private function cache (
      string $route,
      callable $handler,
      null|string|array $methods,
      array $Middlewares
   ): void {
      $normalizedRoute = ($route === '/' ? '' : rtrim($route, '/'));

      // @ Prepend group prefix when flattening nested routes
      if ($this->groupPrefix !== null) {
         // ? Nested routes MUST be relative: a leading '/' would register the
         //   route at the top level (silently bypassing the group prefix)
         //   instead of under the group. Fail loud at registration time.
         //   (Replaces the legacy route() guard removed with the old matcher.)
         if ($route !== '' && $route[0] === '/') {
            throw new InvalidArgumentException('Nested route path must be relative!');
         }

         if ($normalizedRoute !== '') {
            $normalizedRoute = "{$this->groupPrefix}/{$normalizedRoute}";
         }
      }

      $MergedMiddlewares = [...$this->Middlewares, ...$Middlewares];

      // @ Pre-bind Closure to Route — gives handlers `$this->Params` access
      //   and (empirically) keeps Closure call path on JIT-friendly fast track
      //   per PHP 8.4 benchmarks.
      /** @var callable $boundHandler */
      $boundHandler = ($handler instanceof Closure)
         ? $handler->bindTo($this->Route, $this->Route) ?? $handler
         : $handler;

      // @ Catch-all route — store dispatcher closure directly (O(1) callable)
      if ($normalizedRoute === '/*') {
         $this->catchAllCache = $this->dispatch($boundHandler, $MergedMiddlewares);
         return;
      }

      // @ Dynamic route (has :param)
      if (strpos($route, ':') !== false) {
         // @ Group route (catch-all :*) — store for flattening during warmup
         if (strpos($route, ':*') !== false) {
            $prefix = rtrim(explode(':*', $normalizedRoute)[0], '/');

            $methodsList = $methods === null ? [] : (array) $methods;
            $this->pendingGroups[] = [
               'prefix' => $prefix,
               'handler' => $handler,
               'methods' => $methodsList,
               'Middlewares' => $MergedMiddlewares,
            ];
            return;
         }

         // @ Build regex pattern and extract param names + positions
         $parts = explode('/', ltrim($normalizedRoute, '/'));
         /** @var array<string> $regexParts */
         $regexParts = [];
         /** @var array<string> $paramNames */
         $paramNames = [];
         /** @var array<string,int> $paramCounts Track duplicate param names */
         $paramCounts = [];

         foreach ($parts as $index => $part) {
            if (isset($part[0]) && $part[0] === ':') {
               $paramName = trim($part, ':');

               // @ Check for catch-all modifier: :paramName* → capture rest of URL
               $isCatchAll = false;
               if (str_ends_with($paramName, '*')) {
                  $paramName = substr($paramName, 0, -1);
                  $isCatchAll = true;

                  if ($index !== count($parts) - 1) {
                     throw new InvalidArgumentException(
                        "Catch-all param ':$paramName*' must be the last path segment."
                     );
                  }
               }

               if ($isCatchAll) {
                  $regexParts[] = '(.+)';
               }
               // @ Check for named constraint type: :paramName<int> → expand to regex
               else if (str_ends_with($paramName, '>') && ($anglePos = strpos($paramName, '<')) !== false) {
                  $typeName = substr($paramName, $anglePos + 1, -1);
                  $paramName = substr($paramName, 0, $anglePos);
                  if (!isset(self::PARAM_CONSTRAINTS[$typeName])) {
                     throw new InvalidArgumentException(
                        "Unknown route param constraint type '<$typeName>' for param ':$paramName'. "
                        . 'Valid types: ' . implode(', ', array_keys(self::PARAM_CONSTRAINTS)) . '.'
                     );
                  }
                  $constraintRegex = self::PARAM_CONSTRAINTS[$typeName];
                  $regexParts[] = "({$constraintRegex})";
               }
               // @ Check for in-URL regex: :paramName(\d+) → extract name and regex
               else if (str_ends_with($paramName, ')') && ($parenPos = strpos($paramName, '(')) !== false) {
                  $inlineRegex = substr($paramName, $parenPos + 1, -1);
                  $paramName = substr($paramName, 0, $parenPos);
                  $regexParts[] = "({$inlineRegex})";
               }
               // @ Check for pre-set regex constraint on Params
               else {
                  $paramRegex = $this->Route->Params->$paramName;
                  if ($paramRegex !== null && is_string($paramRegex)) {
                     $regexParts[] = "({$paramRegex})";
                  } else {
                     $regexParts[] = '([^\\/]+)';
                  }
               }

               $paramNames[] = $paramName;

               // @ Track duplicate param names
               $paramCounts[$paramName] = ($paramCounts[$paramName] ?? 0) + 1;
            } else {
               $regexParts[] = preg_quote($part, '/');
            }
         }

         $pattern = '/^\\/' . implode('\\/', $regexParts) . '$/';

         $methodsList = $methods === null ? [] : (array) $methods;

         // @ Identify duplicated params (appear > 1 time)
         /** @var array<string,bool> $duplicateParams */
         $duplicateParams = [];
         foreach ($paramCounts as $name => $count) {
            if ($count > 1) {
               $duplicateParams[$name] = true;
            }
         }

         // @ Classify route type: simple (no regex needed) or complex
         $isSimple = ($duplicateParams === []);
         if ($isSimple) {
            foreach ($regexParts as $rp) {
               if ($rp !== '([^\\/]+)') {
                  $isSimple = false;
                  break;
               }
            }
         }

         // @ Build segment-based matching data for simple routes
         $firstSegKey = $parts[0][0] === ':' ? '' : $parts[0];
         /** @var array<int,string> $fixedSegments */
         $fixedSegments = [];
         /** @var array<int> $paramIndices */
         $paramIndices = [];
         if ($isSimple) {
            foreach ($parts as $index => $part) {
               $segPos = $index + 1;
               if (isset($part[0]) && $part[0] === ':') {
                  $paramIndices[] = $segPos;
               } else if ($index > 0 || $firstSegKey === '') {
                  $fixedSegments[$segPos] = $part;
               }
            }
         }

         // @ Segment-count key for indexing (0 = variable-length catch-all)
         $hasCatchAll = false;
         foreach ($regexParts as $rp) {
            if ($rp === '(.+)') {
               $hasCatchAll = true;
               break;
            }
         }
         $segCountKey = $hasCatchAll ? 0 : count($parts);

         $this->dynamicCache[$firstSegKey][$segCountKey][] = [
            'type' => $isSimple ? 'simple' : 'complex',
            'pattern' => $pattern,
            'methods' => $methodsList,
            'paramNames' => $paramNames,
            'fixedSegments' => $fixedSegments,
            'paramIndices' => $paramIndices,
            'duplicateParams' => $duplicateParams,
            'dispatcher' => $this->dispatch($boundHandler, $MergedMiddlewares),
         ];
         $this->hasDynamic = true;
         return;
      }

      // @ Static route — cache stores the dispatcher closure directly
      $methodsList = $methods === null ? [''] : (array) $methods;
      $Dispatcher = $this->dispatch($boundHandler, $MergedMiddlewares);
      foreach ($methodsList as $method) {
         $this->staticCache[$method][$normalizedRoute] = $Dispatcher;
         if ($method === '') {
            $this->hasAgnosticStatic = true;
         }
      }
   }

   // # Routing
   /**
    * Register a route. Pure registration — populates the cache; the actual
    * request is dispatched later by `resolve()` (called from `routing()`).
    *
    * @param null|string|array<string> $methods
    * @param array<Middleware> $middlewares
    */
   public function route (
      string $route,
      callable $handler,
      null|string|array $methods = null,
      array $middlewares = []
   ): false
   {
      // ? Router paused
      if ($this->active === false) {
         return false;
      }
      // ? Cache already warmed — ignore further registrations on this Router
      if ($this->cached === false) {
         $this->cache($route, $handler, $methods, $middlewares);
      }
      return false;
   }
   /**
    * Drain the route Generator once to populate the cache (first request only),
    * then resolve the current request from the cache.
    *
    * $Routes may be null when the SAPI handler registered routes via direct
    * `$Router->route(...)` calls (no yield). Registration already happened
    * synchronously before the encoder reached this method; only the flatten
    * + resolve passes remain.
    */
   public function routing (null|Generator $Routes = null): Generator
   {
      // ! First request: register every route, then flatten group routes
      if ($this->cached === false) {
         $this->Middlewares = [];
         if ($Routes !== null) {
            foreach ($Routes as $_) {
               // @ Intentional drain: route() registers via cache(), yields false
            }
         }
         $this->flatten();
         $this->cached = true;
      }

      // @ Resolve current request from the cache
      $Result = $this->resolve();
      if ($Result instanceof Response) {
         yield $Result;
      }
   }
}
