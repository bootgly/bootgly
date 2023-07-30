<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\modules\HTTP\Server;


use Bootgly;

use Bootgly\ABI\streams\File;

use Bootgly\ACI\Debugger;

use Bootgly\WPI\modules\HTTP\Server\Router\Route;


define('GET', 'GET');
define('HEAD', 'HEAD');
define('POST', 'POST');
define('PUT', 'PUT');
define('DELETE', 'DELETE');
define('CONNECT', 'CONNECT');
define('OPTIONS', 'OPTIONS');
define('TRACE', 'TRACE');
define('PATCH', 'PATCH');


class Router
{
   // * Config
   // @ Boot
   const MODE_FILE = 1;
   const MODE_DIRECTORIES = 2;
   const MODE_DATABASE = 3;
   public int $mode;           // 1 = file configuration, 2 = dynamic (directories), 3 = database
   //public string $base;
   //public array $methods;    // 'route' => [string ...$methods]
   //public array $handlers;   // 'route' => [function ...$handlers]
   //public array $conditions; // 'route' => [mixed ...$handlers]

   // * Data
   public static string $Server;

   // * Meta
   // @ Status
   private bool $active;
   // @ Stats
   public int $routes;
   public array $routed;

   public ? Route $Route;


   public function __construct (string $Server)
   {
      // * Config
      // @ Status
      $this->active = true;
      // @ Boot
      $this->mode = self::MODE_FILE;
      //$this->base = '/';
      //$this->methods = [];
      //$this->handlers = [];
      //$this->conditions = [];

      // * Data
      self::$Server = $Server;

      // * Meta
      // @ History
      $this->routes = 0;
      $this->routed = [];


      $this->Route = new Route($this);

      Debugger::$from = 1;
      Debugger::$to = 10;
   }

   public function boot (string|array $instances = ['routes'])
   {
      $Request = self::$Server::$Request;

      $Router = &$this;
      $Route = &$this->Route;

      $boot = Bootgly::$Project->path . 'router/';

      (static function (string $__default__)
      use ($Request, $Router, $Route) {
         include_once $__default__;
      })( (string) new File($boot . 'index.php') );

      $instances = (array) $instances;
      foreach ($instances as $instance) {
         (static function (string $__routes__)
         use ($Request, $Router, $Route) {
            @include_once $__routes__;
         })( (string) new File($boot . $instance . '.php') );
      }
   }
   public function pause ()
   {
      $this->active = false;
   }
   public function continue ()
   {
      $this->active = true;
   }

   public function build ()
   {
      // TODO received array from boot() with routes and build it
   }

   // control(productController::class, '/products/')
   public function control (string $controller, string $basepath = '')
   {
      // TODO
   }
   // @ default
   public function route (
      string $route,
      \Closure|callable $handler,
      null|string|array $condition = null
   ) : bool
   {
      $this->routes++;

      // ! Route Route
      $Route = &$this->Route;
      // @ Reset
      // If Route nested then process next route
      if ($Route->matched === 2 && !$Route->nested && $route[0] !== '/') {
         $Route->matched = 0;
         $Route->nested = true;
      }

      // @ Check
      if ($this->active === false) return false;
      if ($Route->matched === 2) return false;
      if ($Route->nested && $route[0] === '/') return false;

      // @ Set
      $Route->set(path: $route);

      // @ Match
      if ($Route->nested && $Route->path === '*') { // Not Matched Route (nested level)
         $Route->matched = 1;
      } else if ($Route->path === '/*') { // Not Matched Route (root level)
         $Route->matched = 1;
      } else {
         $Route->matched = $this->match();
      }

      // ! Route Condition
      // @ Match
      if ($Route->matched === 1) {
         switch ($condition) {
            case is_string($condition):
               if (self::$Server::$Request->method === $condition) {
                  $Route->matched = 2;
               }

               break;
            case is_array($condition):
               $found = array_search(
                  needle: self::$Server::$Request->method,
                  haystack: $condition
               );

               if ($found !== false) {
                  $Route->matched = 2;
               }

               break;
         }

         if ( empty($condition) ) {
            $Route->matched = 2;
         }
      }

      // ! Route Callback
      if ($Route->matched === 2) {
         // @ Prepare
         // Set Route Params values
         if ($Route->parameterized) {
            $Params = &$Route->Params;

            foreach ($Params as $param => $value) {
               if ( is_int($value) ) {
                  $Params->$param = @self::$Server::$Request->paths[$value - 1];
               } elseif ( is_array($value) ) {
                  foreach ($value as $i => $l) { // $index => $location
                     $Params->$param[$i] = @self::$Server::$Request->paths[$l - 1];
                  }
               }
            }
         }

         // @ Log
         $this->routed[] = [
            $Route->node,
            $Route->path,
            $Route->parsed
         ];
         $Route::$level++;

         // @ Execute
         if ($handler instanceof \Closure) {
            $handler(
               self::$Server::$Response,
               self::$Server::$Request,
               $Route
            ); // @ Call handler
         } else {
            call_user_func_array(
               $handler,
               [
                  self::$Server::$Response,
                  self::$Server::$Request,
                  $Route
               ]
            );
         }
      }

      return true;
   }

   public function validate () // TODO validate Route
   {
      // TODO
   }
   private function match ()
   {
      $Route = $this->Route;

      if ($Route->parameterized) {
         $this->parse(); // @ Set $Route->parsed and $Route->catched

         if ($Route->parsed) {
            $pattern = '/^' . $Route->parsed . '$/m';

            if ($Route->catched && $Route->catched !== '(.*)') {
               $subject = $Route->catched;
               $Route->catched = '';
            } else {
               $subject = self::$Server::$Request->path;
            }

            preg_match($pattern, $subject, $matches);

            if ($Route->catched === '(.*)') {
               #Debug($matches, $pattern, $subject);
               // TODO check if matches[1] is null
               $Route->catched = @$matches[1];
            }

            if (@$matches[0]) {
               return 1;
            }
         }
      } else {
         if ($Route->nested) {
            if ($Route->path === $Route->catched) {
               return 1;
            }
         } else if ($Route->path === self::$Server::$Request->path) {
            return 1;
         }
      }

      return 0;
   }
   public function parse () // @ Parse Route Path (Parameterized)
   {
      if ($this->active === false) return '';

      $Route = &$this->Route;

      // ! Prepare Route Path
      // ? Route path
      $paths = explode('/', str_replace("/", "\/", $Route->path));
      // ? Request path (full | relative)
      if ($Route->catched) { // Get catched path instead of Request path
         $locations = explode('/', str_replace("/", "\/", $Route->catched));
      } else {
         $locations = explode('/', str_replace("/", "\/", self::$Server::$Request->path));
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
               $node = str_replace(':*', '(.*)', $node); //? Replace with (...) capture everything enclosed
               $Route->catched = '(.*)';
               // TODO error if detected next node after Catch-All param?
            } else if (@$node[0] === ':') { //? Param
               $param = trim($node, ':\\'); //? Get Param name
               // TODO get param name with In-URL Regex
               // TODO validate all $param name 😓 - only accept a-z, A-Z, 0-9 and "-"?

               //? In-URL Regex
               // ! BAD IDEA?! 🤔
               if (@$node[-1] === ')') {
                  // TODO validate In-URL Regex 🥵 - only accept this characters -> "azdDw-[]^|" ???
                  $params = explode('(', rtrim($param, ')'));
                  $param = $params[0];

                  $Route->Params->$param = $params[1];

                  $Route->path = str_replace('(' . $params[1] . ')', '', $Route->path);
               }

               // ? Param without Regex
               if ($Route->Params->$param === null) {
                  // TODO Review Regex to all cases to found the best Regex
                  $Route->Params->$param = '(.*)\/?(.*)';
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
               $Route->parsed = false;
               break;
            }

            if ($index > 0) {
               $Route->parsed .= '/';
            }

            $Route->parsed .= $node;

            if ($Route->catched === '(.*)') break;

            // TODO FIX BUG
            if ($Route->path[-1] === '*' || $Route->path[-2] === '*' || $Route->catched) {
               $Route->nodes++;
            }
         }
      }

      return $Route->parsed;
   }

   public function go (int $n)
   {
      // TODO
   }
   public function push (array $location)
   {
      // TODO
   }
   public function redirect (string $from, string $to, int $status)
   {
      // TODO
   }
}
