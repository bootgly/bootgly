<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Doubles;


use function microtime;
use Closure;
use Throwable;

use Bootgly\ACI\Tests\Doubles\Mock\Call;
use Bootgly\ACI\Tests\Doubles\Mock\Calls;
use Bootgly\ACI\Tests\Doubles\Mock\Proxy;
use Bootgly\ACI\Tests\Doubles\Mock\Stub;
use Bootgly\ACI\Tests\Doubles\Mock\Stubs;


/**
 * Test double — typesafe stand-in for an interface or non-final class.
 *
 *   $Auth = new Mock(\App\Auth::class);
 *   $Auth->stub('check', true);
 *
 *   $Auth->Proxy->check('user');           // → true (recorded as Call)
 *
 *   $Auth->verify('check', times: 1);      // assert exactly one call
 */
class Mock implements Doubling
{
   /**
    * Recorded invocations received by the generated Proxy.
    */
   public Calls $Calls;
   /**
    * Stub rules applied by method name and arguments.
    */
   public Stubs $Stubs;


   // * Data
   /**
    * The class or interface this Mock stands in for.
    */
   public readonly string $target;
   // * Metadata
   /**
    * Typesafe proxy instance — passes `instanceof $target`.
    */
   public private(set) object $Proxy;


   /**
    * Create a Mock for an interface or non-final class target.
    *
    * @param class-string $target
    */
   public function __construct (string $target)
   {
      $this->target = $target;
      $this->Calls = new Calls();
      $this->Stubs = new Stubs();
      $this->Proxy = Proxy::build($target, $this);
   }

   /**
    * Define a stub return for a method. Repeated calls override.
    */
   public function stub (string $method, mixed $return = null): Stub
   {
      $Stub = new Stub($method, $return);
      $this->Stubs->add($Stub);

      return $Stub;
   }

   /**
    * Stable verification helper.
    *
    * @return bool true when the recorded count matches; false otherwise.
    */
   public function verify (string $method, null|int $times = null): bool
   {
      $count = $this->Calls->count($method);

      return $times === null ? $count > 0 : $count === $times;
   }

   /**
    * Reset recorded Calls and configured Stubs.
    */
   public function reset (): static
   {
      $this->Calls->reset();
      $this->Stubs->reset();

      return $this;
   }

   /**
    * Invoked by the generated proxy on every method dispatch.
    *
    * @internal
    * @param array<int,mixed> $arguments
    */
   public function handle (string $method, array $arguments): mixed
   {
      $Stub = $this->Stubs->match($method, $arguments);

      $returned = null;
      $threw = null;

      try {
         if ($Stub !== null) {
            if ($Stub->Throws !== null) {
               $threw = $Stub->Throws;

               throw $Stub->Throws;
            }

            $returned = $Stub->return instanceof Closure
               ? ($Stub->return)(...$arguments)
               : $Stub->return;
         }

         return $returned;
      }
      catch (Throwable $Throwable) {
         $threw ??= $Throwable;

         throw $Throwable;
      }
      finally {
         $this->Calls->push(new Call(
            method: $method,
            arguments: $arguments,
            returned: $returned,
            Threw: $threw,
            at: microtime(true),
         ));
      }
   }
}