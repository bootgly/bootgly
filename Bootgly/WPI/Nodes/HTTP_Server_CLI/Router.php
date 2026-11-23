<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use const T_FN;
use const T_FUNCTION;
use function array_fill;
use function array_intersect;
use function array_keys;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_int;
use function is_object;
use function is_string;
use function ltrim;
use function max;
use function preg_match;
use function preg_quote;
use function rtrim;
use function serialize;
use function spl_object_id;
use function str_ends_with;
use function strlen;
use function strpos;
use function substr;
use function substr_count;
use function token_get_all;
use function trim;
use Closure;
use Error;
use Generator;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use Throwable;

use const Bootgly\WPI;
use Bootgly\ABI\IO\FS\File;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Registration;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Registration\Kinds;
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
   // # Middleware comparison
   /**
    * The largest PHP file, in bytes, whose declarations `tally()` counts: a
    * closure declared in a larger one is another one.
    */
   private const int SOURCE_LIMIT = 262144;

   // * Data
   // # Status
   protected bool $active;
   // # Middleware
   /** @var array<Middleware> */
   private array $Middlewares = [];
   // # Cache
   /** @var array<string,array<string,Closure|string>> */
   private array $staticCache = [];
   /**
    * Root routes use one method-keyed lookup instead of the two-dimensional
    * static table. Values are either an ordinary dispatcher or the immutable
    * body registered through serve().
    *
    * @var array<string,Closure|string>
    */
   private array $RootCache = [];
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
   private null|Closure $CatchAllCache = null;
   /** @var array<array{prefix:string,handler:callable,methods:array<string>,Middlewares:array<Middleware>}> */
   private array $pendingGroups = [];
   private null|string $groupPrefix = null;
   /** @var null|array<string> Active group methods; an empty list is method-agnostic. */
   private null|array $groupMethods = null;

   /**
    * Whether the route cache has been warmed (all routes registered).
    *
    * Plain public property (no getter hook) — read every request on the hot path.
    * Written internally when warming finishes.
    */
   public bool $cached = false;

   // * Metadata
   public Route $Route;
   /**
    * The routes this Router stored, one row per registration, in registration
    * order — a registration log, complete once `$cached` is true.
    *
    * A group, a registration made while the Router is paused or after the
    * warm-up, and one that threw leave no row. A path registered twice keeps
    * both rows: the later static or catch-all registration is the one
    * dispatched, the earlier dynamic one.
    *
    * The log holds one attempt: the server rewinds a cold Router before each
    * run of the request handler, so a route set that runs again registers
    * from the start, and a warm-up that throws puts the list and the dispatch
    * tables back as it found them — see `rewind()`.
    *
    * Written where a route is stored and read nowhere on the request path.
    *
    * @var array<int,Registration>
    */
   public private(set) array $Routes = [];
   // # Route list
   /**
    * Equal method lists of the route list, stored once and shared by the rows
    * that carry them — a warm-up table, released when the warm-up ends.
    *
    * @var array<string,array<int,string>>
    */
   private array $methodLists = [];
   /**
    * Equal parameter lists of the route list, shared the same way.
    *
    * @var array<string,array<int,array{name:string,constraint:null|string,pattern:string}>>
    */
   private array $parameterLists = [];
   /**
    * How many functions and closures each PHP file declares per line, read
    * when a closure in a Middleware is compared during the warm-up.
    *
    * @var array<string,array<int,int>>
    */
   private array $declarations = [];
   // # Attempt
   /**
    * What the Router held before its first registration attempt — a copy
    * `rewind()` takes, dropped when the warm-up ends.
    */
   private null|self $Baseline = null;
   /**
    * Whether a new attempt began and has stored nothing yet: the first
    * registration it stores puts `$Baseline` back before it.
    */
   private bool $stale = false;
   /**
    * Whether the attempt has not called `intercept()` yet: its first one
    * starts from the Middlewares of `$Baseline`.
    */
   private bool $fresh = false;
   /**
    * The intercepted Middlewares each registration was stored with, by
    * methods and path, one entry per stored registration of that pair in an
    * attempt — kept between `rewind()` and the warm-up, which registers on
    * its own (null).
    *
    * @var null|array<string,array<int,array<Middleware>>>
    */
   private null|array $Trail = null;
   /**
    * The last turn the attempt stored of each methods and path pair.
    *
    * @var array<string,int>
    */
   private array $turns = [];


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
    * names. Each name resolves to `routes/<Name>.routes.php`, a generator-closure
    * `(Request, Response, Router): Generator`. A single set is returned directly; multiple
    * sets are composed (`yield from` each) into one handler. Pass the result to
    * `->on(Events::RequestReceived, ...)`.
    *
    * The folder is the router home — reserved for future `router.Config.php` defaults.
    *
    * @param string $path The router folder path.
    */
   public function load (string $path): Closure
   {
      // ? Normalize router folder path
      $path = rtrim($path, '/');

      // @ (future) router.Config.php — customize Router defaults
      //   $Config = new File("$path/router.Config.php"); apply if present.

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

         $Set = new File("$path/routes/$name.routes.php");
         // ? Set file must exist
         if ($Set->exists === false) {
            throw new InvalidArgumentException("Route set not found: $path/routes/$name.routes.php");
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
    *
    * A warm Router ignores the call, as it ignores `route()`: no route is
    * registered after the warm-up for them to apply to.
    */
   public function intercept (Middleware ...$Middlewares): void
   {
      // ? Cache already warmed — a handler that runs again registers nothing
      if ($this->cached) {
         return;
      }
      // ? First intercept() of a new attempt: its Middlewares start from the
      //   mark, not on top of the ones the last attempt intercepted
      if ($this->fresh && $this->Baseline !== null) {
         $this->fresh = false;
         $this->Middlewares = $this->Baseline->Middlewares;
      }

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
    * NEW Response instance — rare in practice) is decided by `complete()`,
    * which `resolve()` calls for it.
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
      // @ Fold right over Middlewares — every frame but the outermost
      for ($i = count($Middlewares) - 1; $i > 0; $i--) {
         $Middleware = $Middlewares[$i];
         $next = $invokable;
         $invokable = function (object $Request, object $Response) use ($Middleware, $next): object {
            return $Middleware->process($Request, $Response, $next);
         };
      }

      // @ Outermost frame: the call the fold would emit for index 0, plus the
      //   route's chain published on the Route for the duration of the
      //   dispatch. It must exist BEFORE the first process() runs — a
      //   middleware may defer() ahead of $next, and the deferred clone
      //   copies the Route right then — and be gone once the onion returned
      //   or threw, so neither a later middleware-free route nor a global
      //   middleware deferring ahead of routing inherits a stale chain.
      // ! No finally: FAST_CALL/FAST_RET defeats the tracing JIT (see
      //   Encoder_::encode). A catch region costs nothing until a throw.
      //   Indicative measurement (2026-08-27, 3e6 calls, best-of-5,
      //   interleaved A/B on a machine under concurrent test load — not
      //   idle): middleware-free dispatch unchanged; one-middleware dispatch
      //   +~22 ns tracing / +~34 ns JIT-off, all of it in the two property
      //   writes.
      $Middleware = $Middlewares[0];
      $next = $invokable;

      return function (object $Request, object $Response) use ($Middleware, $next, $Middlewares): object {
         $Route = $this->Route;
         $Route->Middlewares = $Middlewares;
         try {
            $Result = $Middleware->process($Request, $Response, $next);
         }
         catch (Throwable $Throwable) {
            $Route->Middlewares = [];
            throw $Throwable;
         }
         $Route->Middlewares = [];

         return $Result;
      };
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
            $savedMethods = $this->groupMethods;

            // ! Set group context
            $this->Middlewares = $group['Middlewares'];
            $this->groupPrefix = $group['prefix'];
            $this->groupMethods = $group['methods'];

            try {
               // @ Call group handler to register nested routes
               $Handler = $group['handler'];
               if ($Handler instanceof Closure) {
                  $Handler = $Handler->bindTo($this->Route, $this->Route) ?? $Handler;
               }
               /** @var Generator|mixed $NestedRoutes */
               $NestedRoutes = $Handler();

               // @ Iterate Generator: each yield calls route() → cache()
               //   (handlers that register via direct $Router->route() calls — without
               //    yielding — have already executed by the time $Handler() returned)
               if ($NestedRoutes instanceof Generator) {
                  foreach ($NestedRoutes as $_) {
                     // intentional drain
                  }
               }
            }
            finally {
               // ! Restore state even when nested registration fails.
               $this->Middlewares = $SavedMiddlewares;
               $this->groupPrefix = $savedPrefix;
               $this->groupMethods = $savedMethods;
            }
         }
      }
   }
   private function complete (mixed $Result, Response $Response): Response
   {
      if ($Result instanceof Response) {
         if ($Result !== $Response) {
            // ? A detached deferred Response — the stand-in a resource kept
            //   past its deferred job answers for — answers no request: it is
            //   never adopted as the worker's Response (see Encoder_::encode())
            if ($Result->deferred === false || $Result->Request !== null) {
               $WPI = WPI;
               $WPI->Response = $Result;
            }

            return $Result;
         }

         return $Response;
      }

      return $Response;
   }
   /**
    * Resolve the current request from the route cache.
    *
    * On a warm Router it first empties the Route's `Params`, so no entry the
    * previous request left there reaches this one.
    *
    * @return Response|false|null null = cache miss (no catch-all), false = router paused, Response = matched
    */
   public function resolve (): Response|false|null
   {
      // ! Per-request Route state — the Router keeps one Route, one Params
      //   map, for the worker's life. Nothing the last request left there
      //   reaches this one: a dynamic match's params, an entry a handler
      //   wrote (by assignment, by appending through the by-reference read,
      //   through a reference) or a key a read inserted; static and
      //   catch-all matches dispatch from the cache without touching the
      //   map. Unconditional, because a handler's write raises no flag;
      //   ahead of the paused return, so a paused Router holds no request's
      //   params. A cold Router keeps the map: its pending groups still read
      //   the constraint regexes pre-set there when warm() flattens them.
      $Route = $this->Route;
      if ($this->cached) {
         $Route->Params->set([]);
      }

      if ($this->active === false) {
         return false;
      }

      $WPI = WPI;
      $Request = $WPI->Request;
      $URI = $Request->URI;
      $method = $Request->method;
      $Response = $WPI->Response;

      // ! One URL property-hook read per request — the hook memoizes _URL,
      //   but each read still pays the hook frame.
      $URL = $URI === '/' ? '' : $Request->URL;
      $Route->path = $URI === '/' ? '/' : $URL;

      // 1. Static route lookup — O(1)
      if ($URI === '/') {
         $Dispatcher = $this->RootCache[$method] ?? null;
         if ($Dispatcher === null) {
            $Dispatcher = $this->RootCache[''] ?? null;
         }
         if ($Dispatcher !== null) {
            // @ serve(): immutable body, copied into this request's Response.
            // ! A subtype or a status changed by admission middleware must
            //   retain Response::__invoke() semantics instead of bypassing it.
            if (is_string($Dispatcher)) {
               if ($Response::class !== Response::class || $Response->code !== 200) {
                  return $Response(body: $Dispatcher);
               }

               $Response->Body->raw = $Dispatcher;
               return $Response;
            }

            $Result = $Dispatcher($Request, $Response);
            if ($Result === $Response) {
               return $Response;
            }
            // ? A NEW Response — rare: complete() decides its adoption
            if ($Result instanceof Response) {
               return $this->complete($Result, $Response);
            }
            return $Response;
         }
      }
      else {
         $Dispatcher = $this->staticCache[$method][$URL] ?? null;
         if ($Dispatcher === null) {
            $Dispatcher = $this->staticCache[''][$URL] ?? null;
         }

         if ($Dispatcher !== null) {
            // @ serve(): immutable body, copied into this request's Response.
            // ! See the root path above for the subtype/status guard.
            if (is_string($Dispatcher)) {
               if ($Response::class !== Response::class || $Response->code !== 200) {
                  return $Response(body: $Dispatcher);
               }

               $Response->Body->raw = $Dispatcher;
               return $Response;
            }

            $Result = $Dispatcher($Request, $Response);
            if ($Result === $Response) {
               return $Response;
            }

            // ? A NEW Response — rare: complete() decides its adoption
            if ($Result instanceof Response) {
               return $this->complete($Result, $Response);
            }

            return $Response;
         }
      }

      // 2. Dynamic route lookup — skip entirely when no dynamic routes registered
      //    ($URL is '' when '/' had no static match — no dynamic route can match
      //    the root, and strpos() with offset 1 would throw on an empty haystack)
      if ($this->hasDynamic && $URL !== '') {
         $slashPos = strpos($URL, '/', 1);
         $firstSegment = $slashPos !== false ? substr($URL, 1, $slashPos - 1) : substr($URL, 1);
         $segCount = substr_count($URL, '/');
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
                           $segments = explode('/', $URL);
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
                     if ($segments === null) {
                        $segments = explode('/', $URL);
                     }

                     /** @var array<string,string> $pv */
                     $pv = [];
                     $match = true;
                     foreach ($entry['paramIndices'] as $i => $segIdx) {
                        $value = $segments[$segIdx];
                        // ? Regex parity: a param is ([^\/]+) — it never
                        //   matches an empty segment (e.g. `/users//x`)
                        if ($value === '') {
                           $match = false;
                           break;
                        }
                        $pv[$entry['paramNames'][$i]] = $value;
                     }
                     if (!$match) {
                        continue;
                     }

                     $Route = $this->Route;
                     $Route->path = $URL;
                     $Route->Params->set($pv);
                  }
                  else {
                     // @ Complex route: regex matching
                     if (!preg_match($entry['pattern'], $URL, $matches)) {
                        continue;
                     }

                     $Route = $this->Route;
                     $Route->path = $URL;
                     // @ Batch param extraction — accumulate locally
                     //   (duplicates as arrays) and install with one set()
                     //   instead of magic get/set frames per capture
                     $duplicateParams = $entry['duplicateParams'];
                     /** @var array<string,string|array<int,string>> $pv */
                     $pv = [];
                     foreach ($entry['paramNames'] as $i => $paramName) {
                        if (isset($duplicateParams[$paramName])) {
                           if (isset($pv[$paramName])) {
                              /** @var array<int,string> $list */
                              $list = $pv[$paramName];
                              $list[] = $matches[$i + 1];
                              $pv[$paramName] = $list;
                           }
                           else {
                              $pv[$paramName] = [$matches[$i + 1]];
                           }
                        }
                        else {
                           $pv[$paramName] = $matches[$i + 1];
                        }
                     }
                     $Route->Params->set($pv);
                  }

                  return $this->complete(($entry['dispatcher'])($Request, $Response), $Response);
               }
            }
         }
      }

      // 3. Catch-all fallback
      if ($this->CatchAllCache !== null) {
         // ! Security: DO NOT promote attacker-controlled miss URLs into
         //   `staticCache['']` — `catchAllCache` is already O(1), so
         //   promotion adds no perf benefit while allowing an
         //   unauthenticated client to grow the static cache by one
         //   entry per unique miss URL (bounded memory DoS until the
         //   worker is recycled). Serve directly from `catchAllCache`.
         return $this->complete(($this->CatchAllCache)($Request, $Response), $Response);
      }

      return null;
   }
   /**
    * Populate route cache tables (called once per route definition).
    *
    * @param null|string|array<string> $methods
    * @param array<Middleware> $Middlewares
    * @param null|array<array-key,mixed> $cacheConfig User-supplied cache
    *        options — kept loosely typed so the registration-time guards
    *        below stay meaningful (documented shape: `array{TTL: int}`).
    */
   private function cache (
      string $route,
      callable $handler,
      null|string|array $methods,
      array $Middlewares,
      null|array $cacheConfig = null,
      null|string $directBody = null
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

      // ! A methodless child inherits the active group. Explicit child methods
      //   may only narrow a restrictive parent; a disjoint declaration is a
      //   registration error instead of silently broadening either policy.
      $methodsList = $methods === null ? [] : (array) $methods;
      $methodAgnostic = $methods === null;
      if ($this->groupMethods !== null) {
         if ($methods === null) {
            $methodsList = $this->groupMethods;
            $methodAgnostic = $methodsList === [];
         }
         else if ($this->groupMethods !== []) {
            $methodsList = array_values(array_intersect($this->groupMethods, $methodsList));
            if ($methodsList === []) {
               throw new InvalidArgumentException(
                  'Nested route methods must intersect with parent route-group methods!'
               );
            }
         }
      }

      // @ Intercepted Middlewares of this registration
      $Intercepted = $this->Middlewares;
      $trail = null;
      $turn = 0;
      if ($this->Trail !== null) {
         $trail = implode(',', $methodsList) . " $normalizedRoute";
         $turn = ($this->turns[$trail] ?? -1) + 1;

         // ? A registration an earlier attempt made too: the Middlewares this
         //   attempt intercepted itself up to here, plus the ones it was
         //   stored with then that this attempt did not intercept — never
         //   fewer than either, so a Middleware one run intercepts and
         //   another skips keeps the route behind it
         if (isSet($this->Trail[$trail][$turn])) {
            $Own = $this->Middlewares;
            if ($this->fresh && $this->Baseline !== null) {
               $Own = $this->Baseline->Middlewares;
            }

            $Intercepted = $this->merge($this->Trail[$trail][$turn], $Own);
         }
      }

      $MergedMiddlewares = [...$Intercepted, ...$Middlewares];

      // @ Pre-bind Closure to Route — gives handlers `$this->Params` access
      //   and (empirically) keeps Closure call path on JIT-friendly fast track
      //   per PHP 8.4 benchmarks.
      // ? A `static function` handler cannot be bound, by language rule, and
      //   that is a supported shape — every PoC and most E2E routes use it,
      //   trading `$this->Params` for an explicit parameter. The refusal is
      //   the expected answer and `?? $handler` is what handles it, so the
      //   diagnostic is suppressed at the call rather than left to warn on
      //   every such registration.
      /** @var callable $boundHandler */
      $boundHandler = ($handler instanceof Closure)
         ? @$handler->bindTo($this->Route, $this->Route) ?? $handler
         : $handler;

      // @ Route response cache opt-in: stamp the TTL on the Response before
      //   the handler runs — the encoder (or defer()) consumes it to store the
      //   built wire bytes. Cache replay skips the handler, so any route/group
      //   middleware makes the route ineligible: those admission and response
      //   policies must execute on every request. Middleware-free routes keep
      //   the wrapper-only fast path.
      $cacheOptions = null;
      if ($cacheConfig !== null) {
         // ? Fail loud at registration time — runtime pays nothing for it
         foreach ($cacheConfig as $option => $_) {
            if ($option !== 'TTL') {
               throw new InvalidArgumentException(
                  "Unknown route cache option '{$option}'. Valid options: TTL."
               );
            }
         }

         $cacheTTL = $cacheConfig['TTL'] ?? null;

         if (is_int($cacheTTL) === false || $cacheTTL <= 0) {
            throw new InvalidArgumentException(
               "Route cache config requires a positive integer 'TTL' (seconds)."
            );
         }

         // ! Validated above: exactly ['TTL' => positive int] — the route
         //   list records the caller's own array.
         /** @var array{TTL:int} $cacheConfig */
         $cacheOptions = $cacheConfig;

         if ($MergedMiddlewares === []) {
            $Inner = $boundHandler;
            $boundHandler = static function (object $Request, object $Response) use ($Inner, $cacheTTL): mixed {
               if ($Response instanceof Response) {
                  $Response->cache = $cacheTTL;
               }

               return $Inner($Request, $Response);
            };
         }
      }

      // ! What the route list records as the handler: the callable as it was
      //   registered — serve() has none, its row carries the body instead.
      //   (PHP has no callable property type: a callable is a Closure or an
      //   invokable object, a [target, method] pair or a function name.)
      $registered = null;
      if ($directBody === null && (is_object($handler) || is_array($handler) || is_string($handler))) {
         $registered = $handler;
      }

      // @ Catch-all route — store dispatcher closure directly (O(1) callable)
      if ($normalizedRoute === '/*') {
         $this->rebase();
         $this->trace($trail, $turn, $Intercepted);
         $this->CatchAllCache = $this->dispatch($boundHandler, $MergedMiddlewares);
         // @ Route list row — the single slot is tested against no method
         $this->Routes[] = new Registration(
            path: $normalizedRoute,
            methods: null,
            handler: $registered,
            body: $directBody,
            Middlewares: $MergedMiddlewares,
            parameters: [],
            cache: $cacheOptions,
            Kind: Kinds::CatchAll
         );
         return;
      }

      // @ Dynamic route (has :param)
      if (strpos($route, ':') !== false) {
         // @ Group route (catch-all :*) — store for flattening during warmup
         if (strpos($route, ':*') !== false) {
            $prefix = rtrim(explode(':*', $normalizedRoute)[0], '/');

            $this->rebase();
            $this->trace($trail, $turn, $Intercepted);
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
         /** @var array<int,array{name:string,constraint:null|string,pattern:string}> $parameters Route list: one entry per param */
         $parameters = [];
         // ! Classification input: literal segments never require regex — only
         //   catch-alls, constraints, inline regexes and pre-set Params regexes
         //   do. (The old classifier re-scanned $regexParts for '([^\/]+)'
         //   equality, so any literal — e.g. `/users/:id` — was misclassified
         //   complex and paid preg_match() + $matches per request.)
         $needsRegex = false;

         foreach ($parts as $index => $part) {
            if (isset($part[0]) && $part[0] === ':') {
               $paramName = trim($part, ':');

               $constraint = null;

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
                  $paramPattern = '.+';
                  $regexParts[] = '(.+)';
                  $needsRegex = true;
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
                  $constraint = $typeName;
                  $paramPattern = $constraintRegex;
                  $regexParts[] = "({$constraintRegex})";
                  $needsRegex = true;
               }
               // @ Check for in-URL regex: :paramName(\d+) → extract name and regex
               else if (str_ends_with($paramName, ')') && ($parenPos = strpos($paramName, '(')) !== false) {
                  $inlineRegex = substr($paramName, $parenPos + 1, -1);
                  $paramName = substr($paramName, 0, $parenPos);
                  $paramPattern = $inlineRegex;
                  $regexParts[] = "({$inlineRegex})";
                  $needsRegex = true;
               }
               // @ Check for pre-set regex constraint on Params
               else {
                  $paramRegex = $this->Route->Params->$paramName;
                  if ($paramRegex !== null && is_string($paramRegex)) {
                     $paramPattern = $paramRegex;
                     $regexParts[] = "({$paramRegex})";
                     $needsRegex = true;
                  } else {
                     $paramPattern = '[^\\/]+';
                     $regexParts[] = '([^\\/]+)';
                  }
               }

               $paramNames[] = $paramName;
               $parameters[] = [
                  'name' => $paramName,
                  'constraint' => $constraint,
                  'pattern' => $paramPattern,
               ];

               // @ Track duplicate param names
               $paramCounts[$paramName] = ($paramCounts[$paramName] ?? 0) + 1;
            } else {
               $regexParts[] = preg_quote($part, '/');
            }
         }

         $pattern = '/^\\/' . implode('\\/', $regexParts) . '$/';

         // @ Identify duplicated params (appear > 1 time)
         /** @var array<string,bool> $duplicateParams */
         $duplicateParams = [];
         foreach ($paramCounts as $name => $count) {
            if ($count > 1) {
               $duplicateParams[$name] = true;
            }
         }

         // @ Classify route type: simple (no regex needed) or complex.
         //   Literals are matched by the bucket key + fixedSegments; only
         //   regex-bearing params and duplicate params force the PCRE path.
         $isSimple = ($duplicateParams === [] && $needsRegex === false);

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

         $this->rebase();
         $this->trace($trail, $turn, $Intercepted);
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
         // @ Route list row — an empty method list matches every method;
         //   equal parameter lists are stored once
         $this->Routes[] = new Registration(
            path: $normalizedRoute,
            methods: $methodsList === [] ? null : $methodsList,
            handler: $registered,
            body: $directBody,
            Middlewares: $MergedMiddlewares,
            parameters: $this->parameterLists[serialize($parameters)] ??= $parameters,
            cache: $cacheOptions,
            Kind: Kinds::Dynamic
         );
         return;
      }

      // @ Static route — cache stores either a dispatcher Closure or the
      //   immutable body from serve(). Route/group middleware always keeps
      //   the ordinary dispatcher so policy executes for every request.
      if ($methodAgnostic) {
         $methodsList = [''];
      }
      // ? Nothing to store (an empty, non-agnostic method list) — no row
      if ($methodsList === []) {
         return;
      }
      $this->rebase();
      $this->trace($trail, $turn, $Intercepted);
      $Dispatcher = $directBody !== null && $MergedMiddlewares === []
         ? $directBody
         : $this->dispatch($boundHandler, $MergedMiddlewares);
      foreach ($methodsList as $method) {
         if ($normalizedRoute === '') {
            $this->RootCache[$method] = $Dispatcher;
         }
         else {
            $this->staticCache[$method][$normalizedRoute] = $Dispatcher;
         }
         if ($method === '') {
            $this->hasAgnosticStatic = true;
         }
      }
      // @ Route list row — equal method lists are stored once (a dynamic
      //   row shares its list with the cache entry instead). An empty method
      //   name is the agnostic slot: the route answers every method.
      $this->Routes[] = new Registration(
         path: $normalizedRoute === '' ? '/' : $normalizedRoute,
         methods: in_array('', $methodsList, true)
            ? null
            : $this->methodLists[serialize($methodsList)] ??= $methodsList,
         handler: $registered,
         body: $directBody,
         Middlewares: $MergedMiddlewares,
         parameters: [],
         cache: $cacheOptions,
         Kind: is_string($Dispatcher) ? Kinds::Body : Kinds::Static
      );
   }

   // # Routing
   /**
    * Register a route. Pure registration — populates the cache; the actual
    * request is dispatched later by `resolve()` (called from `routing()`).
    *
    * @param null|string|array<string> $methods
    * @param array<Middleware> $middlewares
    * @param null|array{TTL: int} $cache Route response cache options — the
    *                                    route opts in to the per-worker route
    *                                    response cache (GET only).
    *                                    `TTL`: entry lifetime in seconds.
    */
   public function route (
      string $route,
      callable $handler,
      null|string|array $methods = null,
      array $middlewares = [],
      null|array $cache = null
   ): false
   {
      // ? Router paused
      if ($this->active === false) {
         return false;
      }
      // ? Cache already warmed — ignore further registrations on this Router
      if ($this->cached === false) {
         $this->cache($route, $handler, $methods, $middlewares, $cache);
      }
      return false;
   }
   /**
    * Register an immutable string response.
    *
    * Middleware-bearing, dynamic and catch-all routes keep the ordinary
    * dispatcher path. A middleware-free static route may copy its body into
    * the current per-request Response without a handler Closure invocation.
    *
    * @param null|string|array<string> $methods
    * @param array<Middleware> $middlewares
    */
   public function serve (
      string $route,
      string $body,
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
         $Handler = static function (Request $Request, Response $Response) use ($body): Response {
            return $Response(body: $body);
         };

         $this->cache(
            $route,
            $Handler,
            $methods,
            $middlewares,
            directBody: $body,
         );
      }
      return false;
   }
   /**
    * Warm the route cache without a request: drain the route Generator, then
    * flatten the route groups. `$cached` is true and `$Routes` is complete
    * when it returns; a warm Router ignores the call.
    *
    * `routing()` runs it on the worker's first request. Call it directly to
    * read the route list in a test, a command or a tool:
    * `$Router->warm($Handler($Request, $Response, $Router))`.
    *
    * Pass null when the routes were registered through direct
    * `$Router->route(...)` calls (no yield): only the groups remain.
    *
    * A warm-up that throws leaves the Router cold and as this call found it
    * — the routes registered before the call stay, their groups pending
    * again — unless the route set warmed the Router by itself before it
    * threw.
    *
    * @param null|Generator $Routes The route set to drain.
    *
    * @throws Throwable What a route set or a registration threw.
    */
   public function warm (null|Generator $Routes = null): void
   {
      // ? Already warm
      if ($this->cached) {
         return;
      }

      // ! Where a failed warm-up goes back to
      $Found = clone $this;

      try {
         $this->Middlewares = [];
         // ! A warm-up registers on its own: no trail to take from
         $this->fresh = false;
         $this->Trail = null;
         if ($Routes !== null) {
            foreach ($Routes as $_) {
               // @ Intentional drain: route() registers via cache(), yields false
            }
         }
         // ! An attempt that registered nothing keeps what the last one
         //   left: its groups are flattened as they stand
         $this->stale = false;
         $this->flatten();
      }
      catch (Throwable $Throwable) {
         // ! What the failed attempt stored would otherwise pile up with
         //   every retry, and a route only it registered would go on
         //   answering.
         // ? Unless a route set warmed the Router by itself before it threw
         if ($this->cached === false) {
            $this->restore($Found);
            $this->Middlewares = $Found->Middlewares;
            $this->stale = $Found->stale;
            $this->fresh = $Found->fresh;
            $this->Trail = $Found->Trail;
         }

         throw $Throwable;
      }

      // @ The rows keep what they share; the warm-up tables are done
      $this->methodLists = [];
      $this->parameterLists = [];
      $this->declarations = [];
      $this->turns = [];
      $this->Baseline = null;
      $this->cached = true;
   }
   /**
    * Mark the start of a registration attempt on a cold Router. The first
    * call records what the Router holds. After every later one the
    * first registration puts the recorded state back before it is stored —
    * the dispatch tables, the pending groups and the route list — and the
    * first `intercept()` starts from the recorded Middlewares again. A warm
    * Router ignores the call.
    *
    * The server calls it before each run of the request handler while the
    * Router is cold, so a route set that runs more than once — its handler
    * answered by itself or threw before the warm-up ended — registers once.
    * Routes registered before the first call are part of the mark and stay,
    * and so does everything when an attempt registers nothing: a handler
    * that registers on its first run only keeps its routes.
    *
    * The routes of an attempt are whole or nothing: once it registers one,
    * the routes an earlier attempt registered and this one does not repeat
    * are gone — a handler must not guard part of its routes to run once
    * while the rest runs again. The intercepted Middlewares follow the
    * registration instead, and never shrink: a registration an earlier
    * attempt made too — the same methods and path, the same how-many-th
    * stored time in the attempt (a refused one takes no turn) — is stored
    * with the Middlewares this attempt intercepted itself up to there, in
    * their order, plus each one it had then that this attempt did not
    * intercept, kept in its place (one built again the same way — the
    * same class with the same property values, the objects and closures
    * it holds compared the same way — is the same Middleware, and this
    * attempt's is kept; a configuration that differs, or that cannot be
    * compared — see `compare()` —, is another one, so a Middleware built
    * from what changes on every run is kept once per run); a
    * new one takes the Middlewares intercepted at that point: the last
    * attempt's until this attempt's first `intercept()`, the recorded ones
    * plus this attempt's after it. So a route stays behind a Middleware
    * intercepted on the first run only, or on a later run only. Two shapes
    * still lose a Middleware intercepted on the first run only: a route
    * only a later run registers, after an `intercept()` of that run, when
    * the run skips the first-run-only one; and a path a run registers fewer
    * times than the run before, when an earlier registration of it sat ahead
    * of that first-run-only `intercept()`. A route set that yields its routes
    * is drained by the warm-up, which starts from no intercepted Middleware.
    */
   public function rewind (): void
   {
      // ? Already warm
      if ($this->cached) {
         return;
      }

      // ?: First attempt: nothing to put back yet
      if ($this->Baseline === null) {
         $this->Baseline = clone $this;
         $this->Trail = [];

         return;
      }

      $this->stale = true;
      $this->fresh = true;
      $this->turns = [];
   }
   /**
    * Start the attempt from the mark: the first registration it stores puts
    * back what the Router held before the first attempt, instead of landing
    * on top of the last one. A registration that is refused stores nothing
    * and puts nothing back.
    */
   private function rebase (): void
   {
      // ? Not the first store of a new attempt
      if ($this->stale === false || $this->Baseline === null) {
         return;
      }

      $this->stale = false;
      $this->restore($this->Baseline);
   }
   /**
    * Whether two values are the same for a Middleware — so the same that
    * they behave the same; whatever cannot be told is another one:
    * - arrays: identical (one shared array, in one step), or the same
    *   keys, in the same order, with the same values;
    * - scalars, nulls and resources: identical;
    * - objects: the same instance, or the same class with the same
    *   properties — except an object of a class PHP defines, or of one
    *   extending it, and a lazy object not initialized yet, whose state
    *   the properties do not show: those are the same instance only;
    * - closures: the same instance, or the same declaration — alone on
    *   its line of a file of `SOURCE_LIMIT` bytes at most — with the same
    *   called class, scope, bound object and captured variables.
    * Values are compared this way all the way down, 16 levels at most
    * (deeper is another one); an object pair met again — a cycle, or one
    * shared below both — counts as the same.
    *
    * @param array<string,true> $pairs The object pairs met so far.
    */
   private function compare (mixed $A, mixed $B, array &$pairs, int $depth = 0): bool
   {
      // ? Too deep to tell: another one
      if ($depth > 16) {
         return false;
      }
      // ? Arrays — compared here, value by value, when `===` cannot tell
      if (is_array($A) || is_array($B)) {
         // ? Identical: one shared array is told in one step, however large
         try {
            if ($A === $B) {
               return true;
            }
         }
         catch (Error) { // @phpstan-ignore catch.neverThrown
            // ? An array that holds a reference to itself (`===` throws
            //   "Nesting level too deep"): compared below
         }
         if (is_array($A) === false || is_array($B) === false || array_keys($A) !== array_keys($B)) {
            return false;
         }
         // @@ Each value
         foreach ($A as $key => $value) {
            if ($this->compare($value, $B[$key], $pairs, $depth + 1) === false) {
               return false;
            }
         }

         return true;
      }
      // ? Identical
      if ($A === $B) {
         return true;
      }
      // ? Scalars, nulls and resources are identical or not the same
      if (is_object($A) === false || is_object($B) === false || $A::class !== $B::class) {
         return false;
      }
      // ? An object pair met again: a cycle, or one shared below both
      $first = spl_object_id($A);
      $second = spl_object_id($B);
      $pair = "$first:$second";
      if (isSet($pairs[$pair])) {
         return true;
      }
      $pairs[$pair] = true;

      // ?: Closures: the declaration, alone on its line, the called class,
      //    the scope, the bound object and the captured variables
      if ($A instanceof Closure && $B instanceof Closure) {
         $Function = new ReflectionFunction($A);
         $Other = new ReflectionFunction($B);
         $file = $Function->getFileName();
         $line = $Function->getStartLine();

         return $Function->getName() === $Other->getName()
            && $file === $Other->getFileName()
            && $line === $Other->getStartLine()
            && $Function->getEndLine() === $Other->getEndLine()
            && ($file === false || $this->tally($file, (int) $line) === 1)
            && $Function->getClosureCalledClass()?->name === $Other->getClosureCalledClass()?->name
            && $Function->getClosureScopeClass()?->name === $Other->getClosureScopeClass()?->name
            && $this->compare($Function->getClosureThis(), $Other->getClosureThis(), $pairs, $depth + 1)
            && $this->compare($Function->getStaticVariables(), $Other->getStaticVariables(), $pairs, $depth);
      }
      // ? State the properties do not show: the same instance only
      $Class = new ReflectionClass($A);
      if ($Class->isUninitializedLazyObject($A) || $Class->isUninitializedLazyObject($B)) {
         return false;
      }
      // @@ A class PHP defines, or one it extends
      for ($Ancestor = $Class; $Ancestor !== false; $Ancestor = $Ancestor->getParentClass()) {
         if ($Ancestor->isInternal()) {
            return false;
         }
      }

      // : Objects: the same properties, one level down
      return $this->compare((array) $A, (array) $B, $pairs, $depth);
   }
   /**
    * How many functions and closures are declared on line `$line` of the
    * PHP file `$file` — 0 when it is not a readable file (`eval()`'d code)
    * or is larger than `SOURCE_LIMIT`: its tokens cost the worker tens to
    * a couple of hundred times its size in memory (~58 MB for a dense file
    * at the limit). Each file is read once per warm-up, at most one byte
    * past the limit.
    */
   private function tally (string $file, int $line): int
   {
      // ? Not read yet
      if (isSet($this->declarations[$file]) === false) {
         $lines = [];
         // ? A file gone or unreadable since it was loaded: no declaration
         //   to count, so the closure is another one
         try {
            $source = is_file($file)
               ? @file_get_contents($file, false, null, 0, self::SOURCE_LIMIT + 1)
               : false;
         }
         catch (Throwable) {
            // ? Out of reach since it was loaded (`open_basedir`)
            $source = false;
         }
         // ? Larger than the limit: no declaration to count either
         if ($source !== false && strlen($source) <= self::SOURCE_LIMIT) {
            // @@ Each `function` / `fn` keyword, by the line it starts on
            foreach (token_get_all($source) as $Token) {
               if (is_array($Token) && ($Token[0] === T_FUNCTION || $Token[0] === T_FN)) {
                  $lines[$Token[2]] = ($lines[$Token[2]] ?? 0) + 1;
               }
            }
         }
         $this->declarations[$file] = $lines;
      }

      // :
      return $this->declarations[$file][$line] ?? 0;
   }
   /**
    * The Middlewares a registration an earlier attempt made too is stored
    * with: `$Own`, the ones this attempt intercepted itself, in their order,
    * with each one of `$Kept` — the ones it was stored with then — that
    * `$Own` lacks put back in its place, right after the last one before it
    * that `$Own` holds. A Middleware of `$Kept` and one of `$Own` are the
    * same when `compare()` finds them so — the same instance, or one built
    * again the same way —: a configuration that differs is another
    * Middleware, kept beside it. Each one of `$Own` stands for one of
    * `$Kept` at most: the first one, in its order, still free.
    *
    * A kept one is compared with the free ones of `$Own` of its class only
    * — `compare()` finds no other the same —, and the list is laid out
    * once: a registration costs a pass over the kept ones, each compared
    * with the free own ones of its class, not a copy of the list per kept
    * one put back.
    *
    * @param array<Middleware> $Kept
    * @param array<Middleware> $Own
    *
    * @return array<Middleware>
    */
   private function merge (array $Kept, array $Own): array
   {
      // ! This attempt's own, by class, each not yet standing for a kept one
      $Own = array_values($Own);
      $classes = [];
      foreach ($Own as $index => $Middleware) {
         $classes[$Middleware::class][] = $index;
      }
      $free = array_fill(0, count($Own), true);
      // ! The kept ones put back ahead of each own one (the last: after all)
      $Back = [];
      $cursor = 0;

      // @@ Put back what this attempt lacks, in its place
      foreach ($Kept as $Middleware) {
         foreach ($classes[$Middleware::class] ?? [] as $index) {
            $pairs = [];
            if ($free[$index] && $this->compare($Own[$index], $Middleware, $pairs)) {
               $free[$index] = false;
               $cursor = max($cursor, $index + 1);

               continue 2;
            }
         }

         $Back[$cursor][] = $Middleware;
      }
      // ?: Nothing to put back: this attempt's own, as they are
      if ($Back === []) {
         return $Own;
      }

      // @@ Lay this attempt's own out, each after what goes back ahead of it
      $Merged = [];
      foreach ($Own as $index => $Middleware) {
         foreach ($Back[$index] ?? [] as $Put) {
            $Merged[] = $Put;
         }
         $Merged[] = $Middleware;
      }
      foreach ($Back[count($Own)] ?? [] as $Put) {
         $Merged[] = $Put;
      }

      // :
      return $Merged;
   }
   /**
    * Record a stored registration in the trail of the attempt: the
    * Middlewares `$Intercepted` it was stored with, as the `$turn`-th
    * registration of its methods and path `$trail`. Called where the
    * registration is stored, so one that is refused takes no turn. Does
    * nothing outside an attempt (`$trail` null: the warm-up).
    *
    * @param array<Middleware> $Intercepted
    */
   private function trace (null|string $trail, int $turn, array $Intercepted): void
   {
      // ? Outside an attempt
      if ($trail === null || $this->Trail === null) {
         return;
      }

      $this->turns[$trail] = $turn;
      $this->Trail[$trail][$turn] = $Intercepted;
   }
   /**
    * Put the dispatch tables, the pending groups and the route list of
    * `$Baseline` back on this Router.
    */
   private function restore (self $Baseline): void
   {
      // # Cache
      $this->staticCache = $Baseline->staticCache;
      $this->RootCache = $Baseline->RootCache;
      $this->hasAgnosticStatic = $Baseline->hasAgnosticStatic;
      $this->dynamicCache = $Baseline->dynamicCache;
      $this->hasDynamic = $Baseline->hasDynamic;
      $this->CatchAllCache = $Baseline->CatchAllCache;
      $this->pendingGroups = $Baseline->pendingGroups;
      // # Route list
      $this->methodLists = $Baseline->methodLists;
      $this->parameterLists = $Baseline->parameterLists;
      $this->Routes = $Baseline->Routes;
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
         $this->warm($Routes);
      }

      // @ Resolve current request from the cache
      $Result = $this->resolve();
      if ($Result instanceof Response) {
         yield $Result;
      }
   }
}
