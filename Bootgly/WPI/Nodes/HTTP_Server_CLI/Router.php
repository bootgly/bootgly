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
use function call_user_func_array;
use function count;
use function explode;
use function extract;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_quote;
use function rtrim;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function substr_count;
use function trim;
use Closure;
use Exception;
use Generator;

use const Bootgly\WPI;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\IO\FS\File;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Route;


class Router
{
   // * Config
   // ...
   // # Limits
   private const int MAX_NEGATIVE_CACHE = 10_000;
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
   private array $middlewares = [];
   // # Cache
   /** @var array<string,array<string,array{handler:callable,middlewares:array<Middleware>,pipeline:Closure|null}>> */
   private array $staticCache = [];
   /** @var array<string,array<int,array<array{type:string,pattern:string,methods:array<string>,handler:callable,paramNames:array<string>,paramPositions:array<int>,fixedSegments:array<int,string>,paramIndices:array<int>,middlewares:array<Middleware>,duplicateParams:array<string,bool>,pipeline:Closure|null}>>> */
   private array $dynamicCache = [];
   /** @var null|array{handler:callable,middlewares:array<Middleware>,pipeline:Closure|null} */
   private array|null $catchAllCache = null;
   private int $negativeCacheCount = 0;
   /** @var array<string> */
   private array $groupPrefixes = [];
   /** @var array<array{prefix:string,handler:callable,methods:array<string>,middlewares:array<Middleware>}> */
   private array $pendingGroups = [];
   private null|string $groupPrefix = null;
   private bool $cacheWarmed = false;
   private bool $registering = false;

   /**
    * Whether the route cache has been warmed (all routes registered).
    */
   public bool $cached {
      get => $this->cacheWarmed;
   }

   // * Metadata
   public Route $Route;
   // # Stats
   private int $routes;
   // # History
   /** @var array<list<(callable)|null>|string> */
   private array $routeds;


   public function __construct ()
   {
      // * Config
      // ...

      // * Data
      // # Status
      $this->active = true;

      // * Metadata
      $this->Route = new Route;
      // # Stats
      $this->routes = 0;
      #$this->matched = 0;
      // # History
      $this->routeds = [];
   }

   /**
    * Boot the router with the given instances routes.
    *
    * @param string $path The path to the router directory.
    * @param string|array<string> $instances The instances to boot.
    *
    * @return void
    */
   public function boot (string $path, string|array $instances = ['routes']): void
   {
      // @ Prepare import
      $Request = WPI->Request;
      $Route = &$this->Route;
      $Router = &$this;
      // @ Set import data
      $data = [];
      $data['Request'] = $Request;
      $data['Route'] = $Route;
      $data['Router'] = $Router;

      // @ Instance file
      // ? File path
      $boot = "$path/router/";
      $Index = new File("{$boot}index.php");
      // @ Boot (include router index file)
      if ($Index->file) {
         (static function (string $__file__, array $__data__) {
            extract($__data__);
            include_once $__file__;
         })($Index->file, $data);
      }

      // @ Boot (include routes files)
      $instances = (array) $instances;
      foreach ($instances as $instance) {
         $Instance = new File($boot . $instance . '.php');
         if ($Instance->file) {
            (static function (string $__file__, array $__data__) {
               extract($__data__);
               @include_once $__file__;
            })($Instance->file, $data);
         }
      }
   }
   public function pause (): void
   {
      $this->active = false;
   }
   public function continue (): void
   {
      $this->active = true;
   }

   // # Routing
   private function match (string $route): int
   {
      $Route = &$this->Route;

      if ($Route->parameterized) {
         $this->parse(); // @ Set $Route->parsed and $Route->catched

         if ($Route->parsed) {
            // $pattern
            $pattern = "/^{$Route->parsed}\$/m";
            // $subject
            if ($Route->catched && $Route->catched !== '(.*)') {
               $subject = $Route->catched;
               $Route->catched = '';
            } else {
               $subject = WPI->Request->URL;
            }
            // @
            preg_match($pattern, $subject, $matches);

            if ($Route->catched === '(.*)') {
               // TODO check if matches[1] is null
               $Route->catched = @$matches[1];

               // @ Named catch-all param: store matched value in Params
               if ($Route->catchParam !== '') {
                  $Route->Params->{$Route->catchParam} = @$matches[1];
                  $Route->catchParam = '';
               }
            }

            if (@$matches[0]) {
               return 2;
            }
         }

         return 0;
      }

      if ($Route->nested) {
         if ($route === $Route->catched) {
            return 2;
         }

         return 0;
      }

      return 0;
   }
   private function parse (): string
   {
      if ($this->active === false)
         return '';

      $Route = &$this->Route;

      // ! Prepare Route Path
      // ? Route path
      $paths = explode('/', str_replace("/", "\/", $Route->path));
      // ? Request path (full | relative)
      // Get catched path instead of Request path
      if ($Route->catched) {
         $locations = explode('/', str_replace("/", "\/", $Route->catched));
      } else {
         $locations = explode('/', str_replace("/", "\/", WPI->Request->URL));
      }
      // ? Route Path Node replaced by Regex
      $regex_replaced = [];

      // ! Reset Route->parsed
      if ($Route->nested) {
         $Route->parsed = '';
      } else {
         $Route->parsed = '\\';
      }

      foreach ($paths as $index => $node) {
         if ($index > 0 || $node !== '\\') {
            if (@$node[-1] === '*' || @$node[-2] === '*') { //? Catch-All Param
               // @ Named catch-all param: :query* → extract param name
               $cleanNode = rtrim($node, '*\\');
               if (strlen($cleanNode) > 1 && $cleanNode[0] === ':') {
                  $param = trim($cleanNode, ':\\');
                  $Route->Params->$param = '(.*)';
                  $Route->catchParam = $param;
                  $node = '(.*)';
               } else {
                  $node = str_replace(':*', '(.*)', $node);
               }
               $Route->catched = '(.*)';
            } else if (@$node[0] === ':') { //? Param
               $param = trim($node, ':\\'); //? Get Param name

               //? Named constraint type: :param<int> → expand to regex
               if (str_ends_with($param, '>') && ($anglePos = strpos($param, '<')) !== false) {
                  $typeName = substr($param, $anglePos + 1, -1);
                  $param = substr($param, 0, $anglePos);
                  if (isset(self::PARAM_CONSTRAINTS[$typeName])) {
                     $Route->Params->$param = self::PARAM_CONSTRAINTS[$typeName];
                  }
                  $node = str_replace('<' . $typeName . '>', '', $node);
                  $Route->path = str_replace('<' . $typeName . '>', '', $Route->path);
               }
               //? In-URL Regex
               else if (@$node[-1] === ')') {
                  $params = explode('(', rtrim($param, ')'));
                  $param = $params[0];

                  $Route->Params->$param = $params[1]; // @ Set Param Regex

                  $Route->path = str_replace('(' . $params[1] . ')', '', $Route->path);
               }

               // ? Param without Regex
               if ($Route->Params->$param === null) {
                  $Route->Params->$param = '([^\/]+)';
               }

               if (is_string($Route->Params->$param)) {
                  $node = str_replace(':' . $param, $Route->Params->$param, $node);
                  // @ Store values to use in equals params
                  $regex_replaced[$param] = $Route->Params->$param;

                  // @ Replace param by $index + nodes parsed
                  $Route->Params->$param = $index + $Route->nodes;
               } else {
                  $node = str_replace(':' . $param, $regex_replaced[$param], $node);
                  $Route->Params->$param = (array) $Route->Params->$param;
                  $Route->Params->$param[] = $index + $Route->nodes;
               }
            } else if (@$locations[$index] !== $node) {
               $Route->parsed = '';
               break;
            }

            if ($index > 0) {
               $Route->parsed .= '/';
            }

            $Route->parsed .= $node;

            if ($Route->catched === '(.*)')
               break;

            if ($Route->path[-1] === '*' || $Route->path[-2] === '*' || $Route->catched) {
               $Route->nodes++;
            }
         }
      }

      return $Route->parsed;
   }

   // # Middleware
   /**
    * Register middlewares for the current route group.
    *
    * @param Middleware $middlewares The middlewares to register.
    *
    * @return void
    */
   public function intercept (Middleware ...$middlewares): void
   {
      // @
      foreach ($middlewares as $middleware) {
         $this->middlewares[] = $middleware;
      }
   }
   /**
    * Build a pre-compiled middleware pipeline closure (built once at cache time).
    *
    * @param callable $handler
    * @param array<Middleware> $middlewares
    * @return Closure|null
    */
   private function pipeline (callable $handler, array $middlewares): Closure|null
   {
      if ($middlewares === []) {
         return null;
      }

      // @ Build handler wrapper
      $pipeline = function (object $Request, object $Response) use ($handler): object {
         $Result = $handler($Request, $Response);
         return $Result instanceof Response ? $Result : $Response;
      };

      // @ Build onion pipeline: fold right over middlewares
      for ($i = count($middlewares) - 1; $i >= 0; $i--) {
         $Middleware = $middlewares[$i];
         $next = $pipeline;
         $pipeline = function (object $Request, object $Response) use ($Middleware, $next): object {
            return $Middleware->process($Request, $Response, $next);
         };
      }

      return $pipeline;
   }

   // # Cache
   /**
    * Flatten pending group routes into static/dynamic cache.
    * Calls each group handler to discover nested routes and caches them
    * with fully-qualified paths (e.g. /admin/dashboard).
    */
   private function flatten (): void
   {
      while (!empty($this->pendingGroups)) {
         $groups = $this->pendingGroups;
         $this->pendingGroups = [];

         foreach ($groups as $group) {
            // @ Save state
            $savedMiddlewares = $this->middlewares;
            $savedPrefix = $this->groupPrefix;

            // @ Set group context
            $this->middlewares = $group['middlewares'];
            $this->groupPrefix = $group['prefix'];
            $this->registering = true;

            // @ Call group handler to get nested routes Generator
            $handler = $group['handler'];
            if ($handler instanceof Closure) {
               $handler = $handler->bindTo($this->Route, $this->Route) ?? $handler;
            }
            /** @var Generator|mixed $NestedRoutes */
            $NestedRoutes = $handler();

            // @ Iterate Generator: each yield executes route() → cache()
            if ($NestedRoutes instanceof Generator) {
               foreach ($NestedRoutes as $_) {
                  // route() is called via yield, cache() captures the prefix
               }
            }

            // @ Restore state
            $this->registering = false;
            $this->middlewares = $savedMiddlewares;
            $this->groupPrefix = $savedPrefix;
         }
      }

      // @ Clear group prefixes — all nested routes are now in cache
      $this->groupPrefixes = [];
   }
   /**
    * Resolve the current request from the route cache.
    *
    * @return Response|false|null null = cache miss, false = no match, Response = matched
    */
   public function resolve (): Response|false|null
   {
      $WPI = WPI;
      $url = $WPI->Request->URL;
      $method = $WPI->Request->method;
      $Request = $WPI->Request;
      $ResponseObj = $WPI->Response;

      // 1. Static route lookup — O(1)
      if (isSet($this->staticCache[$method][$url])) {
         $cached = $this->staticCache[$method][$url];

         $pipeline = $cached['pipeline'];
         if ($pipeline !== null) {
            $Result = $pipeline($Request, $ResponseObj);

            if ($Result instanceof Response && $Result !== $ResponseObj) {
               $WPI->Response = $Result;
               return $Result;
            }

            return $ResponseObj;
         }

         /** @var Response|mixed */
         $Result = ($cached['handler'])($Request, $ResponseObj);

         if ($Result instanceof Response && $Result !== $ResponseObj) {
            $WPI->Response = $Result;
         }

         return $Result instanceof Response ? $Result : $ResponseObj;
      }
      // @ Also check method-agnostic static routes
      if (isSet($this->staticCache[''][$url])) {
         $cached = $this->staticCache[''][$url];

         $pipeline = $cached['pipeline'];
         if ($pipeline !== null) {
            $Result = $pipeline($Request, $ResponseObj);

            if ($Result instanceof Response && $Result !== $ResponseObj) {
               $WPI->Response = $Result;
               return $Result;
            }

            return $ResponseObj;
         }

         /** @var Response|mixed */
         $Result = ($cached['handler'])($Request, $ResponseObj);

         if ($Result instanceof Response && $Result !== $ResponseObj) {
            $WPI->Response = $Result;
         }

         return $Result instanceof Response ? $Result : $ResponseObj;
      }

      // 2. Dynamic route lookup — segment-indexed + segment-count bucketed
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
               if ($entry['methods'] !== [] && !in_array($method, $entry['methods'])) {
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

               // @ Execute handler (shared between simple and complex)
               $pipeline = $entry['pipeline'];
               if ($pipeline !== null) {
                  $Result = $pipeline($Request, $ResponseObj);

                  if ($Result instanceof Response && $Result !== $ResponseObj) {
                     $WPI->Response = $Result;
                     return $Result;
                  }

                  return $ResponseObj;
               }

               /** @var Response|mixed */
               $Result = ($entry['handler'])($Request, $ResponseObj);

               if ($Result instanceof Response && $Result !== $ResponseObj) {
                  $WPI->Response = $Result;
                  return $Result;
               }

               return $Result instanceof Response ? $Result : $ResponseObj;
            }
         }
      }

      // 3. Check for group route prefixes — fall back to Generator
      foreach ($this->groupPrefixes as $prefix) {
         if (strpos($url, $prefix) === 0) {
            return null;
         }
      }

      // 4. Catch-all fallback
      if ($this->catchAllCache !== null) {
         $cached = $this->catchAllCache;
         $Result = ($cached['handler'])($Request, $ResponseObj);

         // @ Promote to static cache for O(1) lookup on repeat hits
         if ($this->negativeCacheCount < self::MAX_NEGATIVE_CACHE) {
            $this->staticCache[''][$url] = $cached;
            $this->negativeCacheCount++;
         }

         if ($Result instanceof Response && $Result !== $ResponseObj) {
            $WPI->Response = $Result;
            return $Result;
         }

         return $Result instanceof Response ? $Result : $ResponseObj;
      }

      return null;
   }
   /**
    * Populate route cache tables (called once per route definition).
    *
    * @param string $route
    * @param callable $handler
    * @param null|string|array<string> $methods
    * @param array<Middleware> $middlewares
    */
   private function cache (
      string $route,
      callable $handler,
      null|string|array $methods,
      array $middlewares
   ): void {
      $normalizedRoute = ($route === '/' ? '' : rtrim($route, '/'));

      // @ Prepend group prefix when flattening nested routes
      if ($this->groupPrefix !== null && $normalizedRoute !== '' && $normalizedRoute[0] !== '/') {
         $normalizedRoute = $this->groupPrefix . '/' . $normalizedRoute;
      }

      $mergedMiddlewares = [...$this->middlewares, ...$middlewares];

      // @ Pre-bind Closure to Route
      /** @var callable $boundHandler */
      $boundHandler = ($handler instanceof Closure)
         ? $handler->bindTo($this->Route, $this->Route)
         : $handler;

      // @ Catch-all route
      if ($normalizedRoute === '/*') {
         $this->catchAllCache = [
            'handler' => $boundHandler,
            'middlewares' => $mergedMiddlewares,
            'pipeline' => $this->pipeline($boundHandler, $mergedMiddlewares),
         ];
         return;
      }

      // @ Dynamic route (has :param)
      if (strpos($route, ':') !== false) {
         // @ Group route (catch-all :*) — store for flattening during warmup
         if (strpos($route, ':*') !== false) {
            $prefix = rtrim(explode(':*', $normalizedRoute)[0], '/');
            $this->groupPrefixes[] = $prefix;

            $methodsList = $methods === null ? [] : (array) $methods;
            $this->pendingGroups[] = [
               'prefix' => $prefix,
               'handler' => $handler,
               'methods' => $methodsList,
               'middlewares' => $mergedMiddlewares,
            ];
            return;
         }

         // @ Build regex pattern and extract param names + positions
         $parts = explode('/', ltrim($normalizedRoute, '/'));
         $regexParts = [];
         $paramNames = [];
         $paramPositions = [];
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
                     throw new \InvalidArgumentException(
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
                     throw new \InvalidArgumentException(
                        "Unknown route param constraint type '<$typeName>' for param ':$paramName'. "
                        . 'Valid types: ' . implode(', ', array_keys(self::PARAM_CONSTRAINTS)) . '.'
                     );
                  }
                  $regexParts[] = '(' . self::PARAM_CONSTRAINTS[$typeName] . ')';
               }
               // @ Check for in-URL regex: :paramName(\d+) → extract name and regex
               else if (str_ends_with($paramName, ')') && ($parenPos = strpos($paramName, '(')) !== false) {
                  $inlineRegex = substr($paramName, $parenPos + 1, -1);
                  $paramName = substr($paramName, 0, $parenPos);
                  $regexParts[] = '(' . $inlineRegex . ')';
               }
               // @ Check for pre-set regex constraint on Params
               else {
                  $paramRegex = $this->Route->Params->$paramName;
                  if ($paramRegex !== null && is_string($paramRegex)) {
                     $regexParts[] = '(' . $paramRegex . ')';
                  } else {
                     $regexParts[] = '([^\\/]+)';
                  }
               }

               $paramNames[] = $paramName;
               $paramPositions[] = $index + 1;

               // @ Track duplicate param names
               $paramCounts[$paramName] = ($paramCounts[$paramName] ?? 0) + 1;
            } else {
               $regexParts[] = preg_quote($part, '/');
            }
         }

         $pattern = '/^\\/' . implode('\\/', $regexParts) . '$/';

         $methodsList = $methods === null ? [] : (array) $methods;

         // @ Identify which params are duplicated (appear > 1 time)
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
            'handler' => $boundHandler,
            'paramNames' => $paramNames,
            'paramPositions' => $paramPositions,
            'fixedSegments' => $fixedSegments,
            'paramIndices' => $paramIndices,
            'middlewares' => $mergedMiddlewares,
            'duplicateParams' => $duplicateParams,
            'pipeline' => $this->pipeline($boundHandler, $mergedMiddlewares),
         ];
         return;
      }

      // @ Static route
      $methodsList = $methods === null ? [''] : (array) $methods;
      $pipeline = $this->pipeline($boundHandler, $mergedMiddlewares);
      foreach ($methodsList as $method) {
         $this->staticCache[$method][$normalizedRoute] = [
            'handler' => $boundHandler,
            'middlewares' => $mergedMiddlewares,
            'pipeline' => $pipeline,
         ];
      }
   }

   // @
   /**
    * Route a path to a handler.
    *
    * @param string $route The route path.
    * @param callable $handler The handler to call.
    * @param null|string|array<string> $methods The methods to match.
    * @param array<Middleware> $middlewares The middlewares to apply.
    *
    * @return false|object
    */
   public function route (
      string $route,
      callable $handler,
      null|string|array $methods = null,
      array $middlewares = []
   ): false|object
   {
      // @ Registration-only mode: populate cache, skip matching
      if ($this->registering) {
         $this->cache($route, $handler, $methods, $middlewares);
         return false;
      }

      // @ Cache route definition on first pass (before cache is warm)
      if ($this->cacheWarmed === false) {
         $this->cache($route, $handler, $methods, $middlewares);
      }

      // !
      $Route = &$this->Route;
      $routed = 0;

      // ?
      if ($this->active === false) {
         return false;
      }
      if ($Route->nested && $route[0] === '/') {
         throw new Exception('Nested route path must be relative!');
      }

      // # Route Methods
      // @ Match
      if (empty($methods) || in_array(WPI->Request->method, (array) $methods)) {
         $routed = 1;
      }

      // # Route Route
      // @ Boot
      if ($routed === 1) {
         $route = ($route === '/'
            ? ''
            : rtrim($route, '/')
         );
         $Route->path = $route;

         // @ Match
         $routed = match (true) {
            $route === WPI->Request->URL,
            // Not Matched Route (nested level)
            $Route->nested && $route === '*',
            // Not Matched Route (root level)
            $route === '/*',
               => 2,
            default => $this->match($route)
         };
      }

      // # Route Callback
      if ($routed === 2) {
         // @ Prepare
         // Route Params values
         if ($Route->parameterized) {
            // @ HTTP Server Request
            $Path = new Path(WPI->Request->URL);
            $parts = $Path->parts;
            // @ Router Route
            $Params = &$Route->Params;

            foreach ($Params as $param => $value) {
               if ( is_int($value) ) {
                  $Params->$param = @$parts[$value - 1];
               }
               else if ( is_array($value) ) {
                  foreach ($value as $index => $location) {
                     $Params->$param[$index] = @$parts[$location - 1];
                  }
               }
            }

            $Route->nested = true;
         }

         // @ Call
         // # Merge route-level + group-level middlewares
         $merged = [...$this->middlewares, ...$middlewares];

         if ($handler instanceof Closure) {
            $handler = $handler->bindTo($Route, $Route);

            if ($merged !== []) {
               $GroupGenerator = null;

               $Pipeline = new Middlewares;
               $Pipeline->pipe(...$merged);
               $Response = $Pipeline->process(
                  WPI->Request,
                  WPI->Response,
                  function (object $Request, object $Response) use ($handler, &$GroupGenerator): mixed {
                     $Result = $handler($Request, $Response);

                     // ? Group route (nested) — extract Generator, skip post-processing
                     if ($Result instanceof Generator) {
                        $GroupGenerator = $Result;
                        return $Response;
                     }

                     return $Result instanceof Response ? $Result : $Response;
                  }
               );

               // @ Swap pipeline result with Generator for group routes
               if ($GroupGenerator instanceof Generator) {
                  $Response = $GroupGenerator;
               }
            }
            else {
               $Response = $handler(
                  WPI->Request,
                  WPI->Response
               );
            }
         }
         else {
            if ($merged !== []) {
               $GroupGenerator = null;

               $Pipeline = new Middlewares;
               $Pipeline->pipe(...$merged);
               /** @var Response|Generator */
               $Response = $Pipeline->process(
                  WPI->Request,
                  WPI->Response,
                  function (object $Request, object $Response) use ($handler, $Route, &$GroupGenerator): mixed {
                     $Result = call_user_func_array(
                        callback: $handler,
                        args: [$Request, $Response, $Route]
                     );

                     // ? Group route (nested) — extract Generator, skip post-processing
                     if ($Result instanceof Generator) {
                        $GroupGenerator = $Result;
                        return $Response;
                     }

                     return $Result instanceof Response ? $Result : $Response;
                  }
               );

               // @ Swap pipeline result with Generator for group routes
               if ($GroupGenerator instanceof Generator) {
                  $Response = $GroupGenerator;
               }
            }
            else {
               /** @var Response|Generator */
               $Response = call_user_func_array(
                  callback: $handler,
                  args: [
                     WPI->Request,
                     WPI->Response,
                     $Route
                  ]
               );
            }
         }

         // @ Log
         $this->routes++;
         $Route::$level++;
         $this->routeds[$route] = [
            $handler
         ];

         // @ Reset
         if ($Response instanceof Generator) {
            // Route nested
         }
         else if ($Response && $Response !== WPI->Response && $Response instanceof Response) {
            $WPI = WPI;
            $WPI->Response = $Response;
         }
         $Route->nested = false;

         return $Response;
      }

      return false;
   }
   public function routing (Generator $Routes, bool $nested = false): Generator
   {
      // ! Reset middlewares for new request / save for nested group
      if ($nested === false) {
         $this->middlewares = [];
      }
      $parentMiddlewares = $this->middlewares;

      // @ Resolve from cache (all requests after first)
      if ($this->cacheWarmed && $nested === false) {
         $Result = $this->resolve();
         if ($Result !== null) {
            yield $Result;
            return;
         }
      }

      foreach ($Routes as $Response) {
         // @ Silent drain: consume remaining routes for caching (after first match)
         if ($this->registering) {
            continue;
         }

         if ($Response instanceof Generator) {
            $this->Route->nested = true;
            yield from $this->routing($Response, nested: true);
            // @ Restore parent middlewares on group exit
            $this->middlewares = $parentMiddlewares;
            // @ Continue loop in registration mode to cache remaining routes
            if ($this->cacheWarmed === false && $nested === false) {
               $this->registering = true;
               continue;
            }
            break;
         }
         else if ($Response !== false) {
            #$this->Route->nested = false;
            yield $Response;
            // @ Continue loop in registration mode to cache remaining routes
            if ($this->cacheWarmed === false && $nested === false) {
               $this->registering = true;
               continue;
            }
            break;
         }
         else {
            #$this->Route->nested = false;
            #$this->Route = new Route;
            yield $Response;
         }
      }

      // @ Finalize cache warmup after drain
      if ($this->registering && $nested === false) {
         $this->registering = false;
      }

      // @ Flatten pending group routes into cache and mark as warmed
      if ($this->cacheWarmed === false && $nested === false) {
         $this->flatten();
         $this->cacheWarmed = true;
      }
   }
}
