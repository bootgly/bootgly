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


use function get_class;
use function microtime;
use Throwable;

use Bootgly\ACI\Tests\Doubles\Mock\Call;
use Bootgly\ACI\Tests\Doubles\Mock\Calls;
use Bootgly\ACI\Tests\Doubles\Mock\Proxy;


/**
 * Test double — wraps a real instance, records every call, and delegates.
 *
 *   $Spy = new Spy(new RealAuth());
 *   $Spy->Wrapped->check('user');         // delegated; recorded as Call
 *   $Spy->verify('check', times: 1);
 */
class Spy implements Doubling
{
   /**
    * Recorded invocations received by the generated Wrapped proxy.
    */
   public Calls $Calls;
   /**
    * Real object delegated to by the Wrapped proxy.
    */
   public readonly object $Real;

   /**
    * The recording proxy — typesafe `instanceof` of the real class.
    */
   public private(set) object $Wrapped;


   /**
    * Create a Spy around a real object instance.
    */
   public function __construct (object $Real)
   {
      $this->Real = $Real;
      $this->Calls = new Calls();
      $this->Wrapped = Proxy::build(get_class($Real), $this);
   }

   /**
    * Verify whether a method was called, optionally with an exact count.
    */
   public function verify (string $method, null|int $times = null): bool
   {
      $count = $this->Calls->count($method);

      return $times === null ? $count > 0 : $count === $times;
   }

   /**
    * Reset recorded Calls while keeping the wrapped Real object.
    */
   public function reset (): static
   {
      $this->Calls->reset();

      return $this;
   }

   /**
    * Invoked by the generated proxy — delegates to the real instance.
    *
    * @internal
    * @param array<int,mixed> $arguments
    */
   public function handle (string $method, array $arguments): mixed
   {
      $returned = null;
      $threw = null;

      try {
         $returned = $this->Real->{$method}(...$arguments);

         return $returned;
      }
      catch (Throwable $Throwable) {
         $threw = $Throwable;

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