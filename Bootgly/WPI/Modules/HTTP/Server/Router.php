<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server;


use Bootgly;

use Bootgly\ABI\IO\FS\File;

use Bootgly\WPI\Modules\HTTP\Server\Route;
use Bootgly\WPI\Modules\HTTP\Server\Router\Exceptions\RouteMatchedException;


class Router
{
   public static string $Server;

   // * Config
   // ...

   // * Data
   // @ Status
   protected bool $active;

   // * Meta
   public ? Route $Route;
   // @ Stats
   private int $routes;
   private int $matched; // 0 -> none; 1 = route path; 2 = route path and route condition(s)
   // @ History
   private array $routed;


   public function __construct (string $Server)
   {
      self::$Server = $Server;

      // * Config
      // ...

      // * Data
      // @ Status
      $this->active = true;

      // * Meta
      $this->Route = new Route;
      // @ Stats
      $this->routes = 0;
      $this->matched = 0;
      // @ History
      $this->routed = [];
   }

   public function boot (string|array $instances = ['routes'])
   {
      $Request = self::$Server::$Request;

      $Router = &$this;
      $Route = &$this->Route;

      $boot = Bootgly::$Project->path . 'router/';

      $Index = new File($boot . 'index.php');
      (static function (string $__default__)
      use ($Request, $Router, $Route) {
         include_once $__default__;
      })($Index->file);

      $instances = (array) $instances;
      foreach ($instances as $instance) {
         $Instance = new File($boot . $instance . '.php');
         (static function (string $__routes__)
         use ($Request, $Router, $Route) {
            @include_once $__routes__;
         })($Instance->file);
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

   public function reset ()
   {
      $this->matched = 0;
   }

   // @ default
   public function route (string $route, \Closure|callable $handler, null|string|array $condition = null) : bool
   {
      $Route = &$this->Route;

      // @ Reset
      // If Route nested then process next route
      if ($this->matched === 2 && $Route->nested === false && $route[0] !== '/') {
         $this->matched = 0;
         $Route->nested = true;
      }

      // @ Check
      if ($this->active === false) {
         return false;
      }
      if ($this->matched === 2) {
         return false;
      }
      if ($Route->nested && $route[0] === '/') {
         return false;
      }

      // ! Route Route
      if ($this->matched === 0) {
         // @ Set
         $Route->set(path: $route);

         // @ Match
         if ($Route->nested && $Route->path === '*') { // Not Matched Route (nested level)
            $this->matched = 1;
         } else if ($Route->path === '/*') { // Not Matched Route (root level)
            $this->matched = 1;
         } else {
            $this->matched = $this->match();
         }
      }

      // ! Route Condition
      if ($this->matched === 1) {
         // @ Match
         switch ($condition) {
            case \is_string($condition):
               if (self::$Server::$Request->method === $condition) {
                  $this->matched = 2;
               }

               break;
            case \is_array($condition):
               $found = \array_search(
                  needle: self::$Server::$Request->method,
                  haystack: $condition
               );

               if ($found !== false) {
                  $this->matched = 2;
               }

               break;
         }

         if ( empty($condition) ) {
            $this->matched = 2;
         }
      }

      // ! Route Callback
      if ($this->matched === 2) {
         // @ Prepare
         // Set Route Params values
         if ($Route->parameterized) {
            // @ HTTP Server Request
            // ->Path
            $parts = self::$Server::$Request->Path->parts;
            // @ Router Route
            $Params = &$Route->Params;

            foreach ($Params as $param => $value) {
               if ( \is_int($value) ) {
                  $Params->$param = @$parts[$value - 1];
               } else if ( \is_array($value) ) {
                  foreach ($value as $index => $location) {
                     $Params->$param[$index] = @$parts[$location - 1];
                  }
               }
            }
         }

         // @ Call
         // Closure
         if ($handler instanceof \Closure) {
            // TODO bind $handler to $Route?

            $Response = $handler(
               self::$Server::$Request,
               self::$Server::$Response,
               $Route
            );
         } else {
            // callable
            $Response = \call_user_func_array(
               $handler,
               [
                  self::$Server::$Request,
                  self::$Server::$Response,
                  $Route
               ]
            );
         }
         // @ ?
         if ($Response !== self::$Server::$Response) {
            self::$Server::$Response = $Response;
         }
         // @ Log
         $this->routes++;
         $Route::$level++;
         $this->routed[$route] = [
            $handler
         ];

         throw new RouteMatchedException;
      }

      return true;
   }

   private function match ()
   {
      $Route = &$this->Route;

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

            \preg_match($pattern, $subject, $matches);

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
      $paths = \explode('/', \str_replace("/", "\/", $Route->path));
      // ? Request path (full | relative)
      if ($Route->catched) { // Get catched path instead of Request path
         $locations = \explode('/', \str_replace("/", "\/", $Route->catched));
      } else {
         $locations = \explode('/', \str_replace("/", "\/", self::$Server::$Request->path));
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
               $node = \str_replace(':*', '(.*)', $node); //? Replace with (...) capture everything enclosed
               $Route->catched = '(.*)';
               // TODO error if detected next node after Catch-All param?
            } else if (@$node[0] === ':') { //? Param
               $param = \trim($node, ':\\'); //? Get Param name
               // TODO get param name with In-URL Regex
               // TODO validate all $param name 😓 - only accept a-z, A-Z, 0-9 and "-"?

               //? In-URL Regex
               // ! BAD IDEA?! 🤔
               if (@$node[-1] === ')') {
                  // TODO validate In-URL Regex 🥵 - only accept this characters -> "azdDw-[]^|" ???
                  $params = \explode('(', \rtrim($param, ')'));
                  $param = $params[0];

                  $Route->Params->$param = $params[1];

                  $Route->path = \str_replace('(' . $params[1] . ')', '', $Route->path);
               }

               // ? Param without Regex
               if ($Route->Params->$param === null) {
                  // TODO Review Regex to all cases to found the best Regex
                  $Route->Params->$param = '(.*)\/?(.*)';
               }

               if (\is_string($Route->Params->$param)) {
                  $node = \str_replace(':' . $param, $Route->Params->$param, $node);
                  // @ Store values to use in equals params
                  $regex_replaced[$param] = $Route->Params->$param;

                  // @ Replace param by $index + nodes parsed
                  $Route->Params->$param = $index + $Route->nodes;
               } else {
                  $node = \str_replace(':' . $param, $regex_replaced[$param], $node);
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
}
