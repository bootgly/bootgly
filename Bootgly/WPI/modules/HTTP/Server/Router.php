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

use Bootgly\ABI\__Array;
use Bootgly\ABI\__String;
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
   // ...

   public ? Route $Route;


   public function __construct (string $Server)
   {
      // * Config
      $this->mode = self::MODE_FILE;
      //$this->base = '/';
      //$this->methods = [];
      //$this->handlers = [];
      //$this->conditions = [];

      // * Data
      self::$Server = $Server;

      // * Meta
      //$this->history = [];


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
      $this->Route->status = false;
   }
   public function continue ()
   {
      $this->Route->status = true;
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
   public function route (string|array $route, mixed $handler, ...$conditions) : bool
   {
      // ! Route Route
      $Route = &$this->Route;
      // @ Construct
      $Route->index++;

      if ( is_string($route) ) {
         $route = [
            'path' => $route
         ];
      } else { // @ array
         if ( ! isSet($route['path']) ) {
            return false;
         }

         $this->match($route);
      }

      // @ Reset
      // If Route nested then process next route
      if ($Route->matched === 2 && !$Route->nested && $route['path'][0] !== '/') {
         $Route->matched = 0;
         $Route->nested = true;
      }

      // @ Check
      if ($Route->status === false) return false;
      if ($Route->matched === 2) return false;
      if ($Route->nested && $route['path'][0] === '/') return false;

      // @ Set
      $Route->path = $route['path'];

      // @ Match
      if ($Route->nested && $route['path'] === '*') { // 404 Not Matched Route in nested routes
         $Route->matched = 1;
      } else if ($route['path'] === '/*') { // 404 Not Matched Route in root level
         $Route->matched = 1;
      } else {
         $Route->matched = $this->match();
      }

      // ! Route Condition
      // @ Match
      if ($Route->matched === 1) {
         foreach ($conditions as $condition) {
            switch ($condition) {
               case is_string($condition):
                  if (self::$Server::$Request->method === $condition) {
                     $Route->matched = 2;
                  }

                  break;
               case is_array($condition):
                  $Result = __Array::search(
                     $condition, self::$Server::$Request->method
                  );

                  if ($Result->found) {
                     $Route->matched = 2;

                     break;
                  } else {
                     $Route->matched = 1;

                     break 2;
                  }
               case is_bool($condition):
                  if ($condition) {
                     $Route->matched = 2;

                     break;
                  } else {
                     $Route->matched = 1;

                     break 2;
                  }
               case $condition instanceof \Closure:
                  if ($condition(self::$Server::$Response) === true) {
                     $Route->matched = 2;

                     break;
                  } else {
                     $Route->matched = 1;

                     break 2;
                  }
            }
         }

         if (empty($conditions)) {
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
         $Route->routed[] = [
            $Route->node,
            $Route->path,
            $Route->parsed
         ];
         $Route->level++;

         // @ Execute
         switch (gettype($handler)) {
            case 'object':
               if ($handler instanceof \Closure) {
                  $handler(
                     self::$Server::$Response,
                     self::$Server::$Request,
                     $Route
                  ); // @ Call handler
               }

               break;
            case 'array':
               // TODO
               call_user_func(...$handler);
         }
      }

      return true;
   }

   public function validate () // TODO validate Route
   {
      // TODO
   }
   private function match (array $route = [])
   {
      if ($this->Route->status === false) return;

      if ($this->Route->parameterized) {
         $this->parse(); // @ Set $Route->parsed and $Route->catched

         if ($this->Route->parsed) {
            $pattern = '/^' . $this->Route->parsed . '$/m';

            if ($this->Route->catched && $this->Route->catched !== '(.*)') {
               $subject = $this->Route->catched;
               $this->Route->catched = '';
            } else {
               $subject = self::$Server::$Request->path;
            }

            preg_match($pattern, $subject, $matches);

            if ($this->Route->catched === '(.*)') {
               #Debug($matches, $pattern, $subject);
               // TODO check if matches[1] is null
               $this->Route->catched = @$matches[1];
            }

            if (@$matches[0]) {
               return 1;
            }
         }
      } else {
         if ($this->Route->nested) {
            $String = new __String(self::$Server::$Request->path);
            $relative_url = $String->cut(
               $this->Route->routed[$this->Route->level - 1][0], '^'
            );
            return ($this->Route->path === $relative_url ? 1 : 0);
         } else {
            return ($this->Route->path === self::$Server::$Request->path ? 1 : 0);
         }
      }

      return 0;
   }
   public function parse () // @ Parse Route Path (Parameterized)
   {
      if ($this->Route->status === false) return;

      // ! Prepare Route Path
      // ? Route path
      $paths = explode('/', str_replace("/", "\/", $this->Route->path));
      // ? Request path (full | relative)
      if ($this->Route->catched) { // Get catched path instead of Request path
         $locations = explode('/', str_replace("/", "\/", $this->Route->catched));
      } else {
         $locations = explode('/', str_replace("/", "\/", self::$Server::$Request->path));
      }
      // ? Route Path Node replaced by Regex
      $regex_replaced = [];

      // ! Reset Route->parsed
      if ($this->Route->nested) {
         $this->Route->parsed = '';
      } else {
         $this->Route->parsed = '\\';
      }

      foreach ($paths as $index => $node) {
         if ($index > 0 || $node !== '\\') {
            if (@$node[-1] === '*' || @$node[-2] === '*') { //? Catch-All Param
               $node = str_replace(':*', '(.*)', $node); //? Replace with (...) capture everything enclosed
               $this->Route->catched = '(.*)';
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

                  $this->Route->Params->$param = $params[1];

                  $this->Route->path = str_replace('(' . $params[1] . ')', '', $this->Route->path);
               }

               // ? Param without Regex
               if ($this->Route->Params->$param === null) {
                  // TODO Review Regex to all cases to found the best Regex
                  $this->Route->Params->$param = '(.*)\/?(.*)';
               }

               if (is_string($this->Route->Params->$param)) {
                  $node = str_replace(':' . $param, $this->Route->Params->$param, $node);
                  // @ Store values to use in equals params
                  $regex_replaced[$param] = $this->Route->Params->$param;

                  // @ Replace param by $index + nodes parsed
                  $this->Route->Params->$param = $index + $this->Route->nodes;
               } else {
                  $node = str_replace(':' . $param, $regex_replaced[$param], $node);
                  $this->Route->Params->$param = (array)$this->Route->Params->$param;
                  $this->Route->Params->$param[] = $index + $this->Route->nodes;
               }
            } else if (@$locations[$index] !== $node) {
               $this->Route->parsed = false;
               break;
            }

            if ($index > 0) {
               $this->Route->parsed .= '/';
            }

            $this->Route->parsed .= $node;

            if ($this->Route->catched === '(.*)') break;

            // TODO FIX BUG
            if ($this->Route->path[-1] === '*' || $this->Route->path[-2] === '*' || $this->Route->catched) {
               $this->Route->nodes++;
            }
         }
      }

      return $this->Route->parsed;
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
