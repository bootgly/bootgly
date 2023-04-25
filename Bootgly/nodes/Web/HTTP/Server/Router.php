<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server;


use Bootgly\Bootgly;
use function Bootgly\__Array;
use function Bootgly\__String;
use Bootgly\Debugger;
use Bootgly\File;
use Bootgly\Web\HTTP\Server;
use Bootgly\Web\HTTP\Server\Router\Route;


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
   // ...

   // * Meta
   // ...

   public ? Route $Route;


   public function __construct ()
   {
      // * Config
      $this->mode = self::MODE_FILE;
      //$this->base = '/';
      //$this->methods = [];
      //$this->handlers = [];
      //$this->conditions = [];

      // * Data
      // ...

      // * Meta
      //$this->history = [];


      $this->Route = new Route($this);

      Debugger::$from = 1;
      Debugger::$to = 10;
   }

   public function __invoke (...$x)
   {
      return $this->route(...$x);
   }
   public function __call (string $name, array $arguments)
   {
      switch ($name) {
         case 'get':
         case 'post':
         case 'put':
         case 'delete':
         case 'head':
         case 'connect':
         case 'trace':
         case 'options':
            if ($arguments[0] === null || $arguments[1] === null) {
               break;
            }

            $arguments[] = strtoupper($name);

            return $this->route(...$arguments);

         default:
            return $this->$name(...$arguments);
      }
   }

   public function boot (string|array $instances = ['routes'])
   {
      $Request = &Server::$Request;

      $Router = &$this;
      $Route = &$this->Route;

      (static function (string $__default__)
      use ($Request, $Router, $Route) {
         include_once $__default__;
      })( (string) new File(Bootgly::$Project->path . 'router/' . 'index.php') );

      $instances = (array) $instances;
      foreach ($instances as $instance) {
         (static function (string $__routes__)
         use ($Request, $Router, $Route) {
            @include_once $__routes__;
         })( (string) new File(Bootgly::$Project->path . 'router/' . $instance . '.php') );
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
   public function route (string|array $route, mixed $handler, ...$conditions)
   {
      // ! Route Route
      // ? Construct
      $this->Route->index++;

      if (is_string($route)) {
         $route = [
            'path' => $route
         ];
      } else { // @ array
         // TODO
         $this->match($route);

         if ($route['path'] !== null) {
            return;
         }
      }

      // ? Reset
      // If Route nested then process next route
      if ($this->Route->matched === 2 && !$this->Route->nested && $route['path'][0] !== '/') {
         $this->Route->matched = 0;
         $this->Route->nested = true;
      }

      // ? Check
      if ($this->Route->status === false) return;
      if ($this->Route->matched === 2) return;
      if ($this->Route->nested && $route['path'][0] === '/') return;

      // ? Set
      $this->Route->path = $route['path'];

      // ? Match
      if ($this->Route->nested && $route['path'] === '*') { // 404 Not Matched Route in nested routes
         $this->Route->matched = 1;
      } else if ($route['path'] === '/*') { // 404 Not Matched Route in root level
         $this->Route->matched = 1;
      } else {
         $this->Route->matched = $this->match();
      }

      //! Route Condition
      // ? Match
      if ($this->Route->matched === 1) {
         foreach ($conditions as $condition) {
            switch ($condition) {
               case is_string($condition):
                  if (Server::$Request->method === $condition) {
                     $this->Route->matched = 2;
                  }

                  break;
               case is_array($condition):
                  if (__Array($condition)->search(Server::$Request->method)->found) {
                     $this->Route->matched = 2;

                     break;
                  } else {
                     $this->Route->matched = 1;

                     break 2;
                  }
               case is_bool($condition):
                  if ($condition) {
                     $this->Route->matched = 2;

                     break;
                  } else {
                     $this->Route->matched = 1;

                     break 2;
                  }
               case $condition instanceof \Closure:
                  if ($condition(Server::$Response) === true) {
                     $this->Route->matched = 2;

                     break;
                  } else {
                     $this->Route->matched = 1;

                     break 2;
                  }
            }
         }

         if (empty($conditions)) {
            $this->Route->matched = 2;
         }
      }

      // ! Route Callback
      if ($this->Route->matched === 2) {
         // ? Prepare
         // Set Route Params values
         if ($this->Route->parameterized) {
            foreach ($this->Route->Params as $param => $value) {
               if (is_int($value)) {
                  $this->Route->Params->$param = @Server::$Request->paths[$value - 1];
               } elseif (is_array($value)) {
                  foreach ($value as $param_index => $location_index) {
                     $this->Route->Params->$param[$param_index] = @Server::$Request->paths[$location_index - 1];
                  }
               }
            }
         }

         // ? Log
         $this->Route->routed[] = [
            $this->Route->node,
            $this->Route->path,
            $this->Route->parsed
         ];
         $this->Route->level++;

         // ? Execute
         switch (gettype($handler)) {
            case 'object':
               if ($handler instanceof \Closure) {
                  $handler(Server::$Response, Server::$Request, $this->Route); // @ Call handler
               } else {
                  exit();
               }

               break;
            case 'array':
               // TODO
               #Debug($handler);
               call_user_func(...$handler);
         }
      }

      return $this;
   }

   public function validate () // TODO validate Route
   {
      // TODO
   }
   private function match (array $route = []) // @ Route path match
   {
      if ($this->Route->status === false) return;

      if ($route !== []) {
         Debug($route);
      } else {
         if ($this->Route->parameterized) {
            $this->parse(); // @ Set $Route->parsed and $Route->catched

            if ($this->Route->parsed) {
               $pattern = '/^' . $this->Route->parsed . '$/m';

               if ($this->Route->catched && $this->Route->catched !== '(.*)') {
                  $subject = $this->Route->catched;
                  $this->Route->catched = '';
               } else {
                  $subject = Server::$Request->path;
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
               $relative_url = __String(
                  Server::$Request->path)->cut($this->Route->routed[$this->Route->level - 1][0], '^'
               );
               return ($this->Route->path === $relative_url ? 1 : 0);
            } else {
               return ($this->Route->path === Server::$Request->path ? 1 : 0);
            }
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
         $locations = explode('/', str_replace("/", "\/", Server::$Request->path));
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
