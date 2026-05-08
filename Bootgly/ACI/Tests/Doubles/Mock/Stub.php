<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Doubles\Mock;


use Closure;
use Throwable;


/**
 * Stub rule — describes how a mocked method invocation should respond.
 *
 * - $return is yielded as-is unless it's a Closure, in which case it's
 *   invoked with the call arguments and its result is returned.
 * - $Throws, when set, takes precedence over $return.
 * - $Matcher, when set, gates whether this stub fires for a given args list.
 */
final class Stub
{
   /**
    * Throwable to raise when this stub matches.
    */
   public null|Throwable $Throws = null;
   /**
    * Optional predicate used to accept/reject an argument list.
    */
   public null|Closure $Matcher = null;


   // * Config
   /**
    * The value to return when the stub is invoked.
    */
   public mixed $return;
   // * Data
   /**
    * The method this stub applies to.
    */
   public string $method;


   /**
    * Create a stub rule for a target method.
    */
   public function __construct (string $method, mixed $return = null)
   {
      // * Data
      $this->method = $method;

      // * Config
      $this->return = $return;
   }

   /**
    * Configure the stub to throw instead of returning a value.
    */
   public function throw (Throwable $Throwable): self
   {
      $this->Throws = $Throwable;

      return $this;
   }

   /**
    * Configure an argument predicate for this stub rule.
    */
   public function filter (Closure $Matcher): self
   {
      $this->Matcher = $Matcher;

      return $this;
   }

   /**
    * @param array<int,mixed> $arguments
    */
   public function check (string $method, array $arguments): bool
   {
      if ($method !== $this->method) {
         return false;
      }

      if ($this->Matcher === null) {
         return true;
      }

      return (bool) ($this->Matcher)(...$arguments);
   }
}