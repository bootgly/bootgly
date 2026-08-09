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
use function array_reverse;
use function array_unshift;
use function count;
use Closure;


class Middlewares
{
   // * Config
   // ...

   // * Data
   /**
    * The pipeline itself — the array `process()` folds into the onion.
    *
    * Publicly READABLE so a hot-path caller can decide whether this pipeline
    * has anything to run without paying a call frame and, decisively, without
    * trusting a derived counter: a counter that disagreed with this array
    * would skip real middlewares, while this array is the same authority
    * `process()` consults. Writes stay private — only prepend/append/pipe.
    *
    * @var array<Middleware>
    */
   public private(set) array $stack = [];

   // * Metadata
   /**
    * How many middlewares this pipeline will run.
    *
    * Read by route-cache eligibility (audit 2026-07-27 H1): a global pipeline
    * can admit more than one valid principal, and the route-cache key carries
    * no admitted identity, so a route guarded only by global middleware must
    * not store replayable wire.
    */
   // ! Keep this as a direct-read property: Encoder_ reads it on every HTTP
   //   request, while every stack mutation happens during application boot.
   //   The non-zero declaration is deliberate. Reflection construction and
   //   subclasses that skip this constructor must disable shared route-cache
   //   reuse until a normal construction or a stack mutation establishes the
   //   exact count.
   public private(set) int $count = 1;


   public function __construct ()
   {
      $this->count = 0;
   }
   public function __clone ()
   {
      $this->synchronize();
   }
   public function __wakeup (): void
   {
      $this->synchronize();
   }


   public function prepend (Middleware $Middleware): self
   {
      // @
      array_unshift($this->stack, $Middleware);
      $this->count = count($this->stack);

      // :
      return $this;
   }

   public function append (Middleware $Middleware): self
   {
      // @
      $this->stack[] = $Middleware;
      $this->count = count($this->stack);

      // :
      return $this;
   }

   public function pipe (Middleware ...$middlewares): self
   {
      // @
      foreach ($middlewares as $Middleware) {
         $this->stack[] = $Middleware;
      }
      if ($middlewares !== []) {
         $this->count = count($this->stack);
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

   /**
    * Restore the derived count without turning an unconstructed empty object
    * into a cache-admitting pipeline.
    */
   private function synchronize (): void
   {
      if ($this->stack === []) {
         $this->count = $this->count === 0 ? 0 : 1;
         return;
      }

      $this->count = count($this->stack);
   }
}
