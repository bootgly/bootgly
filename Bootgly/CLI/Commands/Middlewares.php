<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Commands;


use function array_reduce;
use function array_reverse;
use function array_unshift;
use Closure;

use Bootgly\CLI\Command;


class Middlewares
{
   // * Config
   // ...

   // * Data
   /** @var array<Middleware> */
   private array $Stack = [];

   // * Metadata
   // ...


   public function prepend (Middleware $Middleware): self
   {
      // @
      array_unshift($this->Stack, $Middleware);

      // :
      return $this;
   }

   public function append (Middleware $Middleware): self
   {
      // @
      $this->Stack[] = $Middleware;

      // :
      return $this;
   }

   public function pipe (Middleware ...$middlewares): self
   {
      // @
      foreach ($middlewares as $Middleware) {
         $this->Stack[] = $Middleware;
      }

      // :
      return $this;
   }

   /**
    * Process the command through the middleware pipeline.
    *
    * @param Command $Command The command being executed.
    * @param array<string> $arguments The arguments passed to the command.
    * @param array<string,bool|int|string> $options The options passed to the command.
    * @param Closure $handler The innermost handler (command runner).
    *
    * @return bool
    */
   public function process (Command $Command, array $arguments, array $options, Closure $handler): bool
   {
      // ? No middlewares — call handler directly
      if ($this->Stack === []) {
         return $handler($Command, $arguments, $options);
      }

      // @ Build the onion from inside out (fold right)
      $Pipeline = array_reduce(
         array: array_reverse($this->Stack),
         callback: function (Closure $Next, Middleware $Middleware): Closure {
            return function (Command $Command, array $arguments, array $options) use ($Middleware, $Next): bool {
               return $Middleware->process($Command, $arguments, $options, $Next);
            };
         },
         initial: $handler
      );

      // :
      return $Pipeline($Command, $arguments, $options);
   }
}
