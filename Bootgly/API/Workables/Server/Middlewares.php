<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Workables\Server;


use function array_reduce;
use function count;
use function array_reverse;
use function array_unshift;
use Closure;


class Middlewares
{
   // * Config
   // ...

   // * Data
   /** @var array<Middleware> */
   private array $stack = [];

   // * Metadata
   /**
    * How many middlewares this pipeline will run.
    *
    * Read by route-cache eligibility (audit 2026-07-27 H1): a global pipeline
    * can admit more than one valid principal, and the route-cache key carries
    * no admitted identity, so a route guarded only by global middleware must
    * not store replayable wire.
    */
   public int $count {
      get => count($this->stack);
   }


   public function prepend (Middleware $Middleware): self
   {
      // @
      array_unshift($this->stack, $Middleware);

      // :
      return $this;
   }

   public function append (Middleware $Middleware): self
   {
      // @
      $this->stack[] = $Middleware;

      // :
      return $this;
   }

   public function pipe (Middleware ...$middlewares): self
   {
      // @
      foreach ($middlewares as $Middleware) {
         $this->stack[] = $Middleware;
      }

      // :
      return $this;
   }

   public function process (object $Request, object $Response, Closure $handler): mixed
   {
      // ? No middlewares — call handler directly
      if ($this->stack === []) {
         return $handler($Request, $Response);
      }

      // @ Build the onion from inside out (fold right)
      $Pipeline = array_reduce(
         array: array_reverse($this->stack),
         callback: function (Closure $next, Middleware $Middleware): Closure {
            return function (object $Request, object $Response) use ($Middleware, $next): object {
               return $Middleware->process($Request, $Response, $next);
            };
         },
         initial: $handler
      );

      // :
      return $Pipeline($Request, $Response);
   }
}