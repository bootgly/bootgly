<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Server;


use function array_reduce;
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
   // ...


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
