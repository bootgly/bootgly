<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use Throwable;

use Bootgly\ACI\Tests\Fixture\Lifecycles;
use Bootgly\ACI\Tests\Fixture\State;


/**
 * Test Fixture — owns deterministic state required to run a test in isolation.
 *
 * Lifecycle is idempotent: prepare()/dispose() are guarded unless the fixture
 * is in the matching prerequisite phase. A disposed fixture can be prepared
 * again; it is reset to the seed first. Test runners may call prepare()
 * earlier than ACI\Tests\Suite\Test::pretest() (e.g. WPI E2E runners that
 * need state ready before the request closure runs); the second call from
 * the base runner becomes a guarded no-op.
 *
 * Concrete domain fixtures live in their owning layer (WPI, ADI, …),
 * not in ACI.
 */
abstract class Fixture
{
   /**
    * Current lifecycle state of the Fixture.
    */
   public Lifecycles $Lifecycle = Lifecycles::Pristine;
   /**
    * Mutable state bag reset from the constructor seed.
    */
   public State $State;


   /**
    * @param array<string,mixed> $bag Initial state bag (snapshotted as the reset seed).
    */
   public function __construct (array $bag = [])
   {
      $this->State = new State($bag);
   }

   /**
    * Bring the world to a known state.
    *
    * Idempotent while already prepared; disposed fixtures are reset first.
    * Subclasses override setup() (called within the lifecycle guard).
    */
   final public function prepare (): void
   {
      if ($this->Lifecycle === Lifecycles::Disposed) {
         $this->reset();
      }

      if ($this->Lifecycle !== Lifecycles::Pristine) {
         return;
      }

      $this->Lifecycle = Lifecycles::Preparing;

      try {
         $this->setup();
      }
      catch (Throwable $Throwable) {
         $this->Lifecycle = Lifecycles::Pristine;

         throw $Throwable;
      }

      $this->Lifecycle = Lifecycles::Ready;
   }

   /**
    * Tear down the world.
    *
    * Idempotent: returns immediately unless Ready.
    * Subclasses override teardown() (called within the lifecycle guard).
    */
   final public function dispose (): void
   {
      if ($this->Lifecycle !== Lifecycles::Ready) {
         return;
      }

      $this->Lifecycle = Lifecycles::Disposing;

      try {
         $this->teardown();
      }
      finally {
         $this->Lifecycle = Lifecycles::Disposed;
      }
   }

   /**
    * Re-seat the fixture between cases.
    *
    * Resets state bag to the seed and lifecycle to Pristine, allowing
    * prepare()/dispose() to fire again cleanly.
    */
   public function reset (): void
   {
      $this->State->reset();
      $this->Lifecycle = Lifecycles::Pristine;
   }

   /**
    * Read a value from the state bag.
    */
   public function fetch (string $key, mixed $default = null): mixed
   {
      return $this->State->fetch($key, $default);
   }

   /**
    * Subclass hook — runs once on prepare() if Pristine.
    *
    * Default: no-op. Concrete fixtures override to seed DB rows, build
    * raw HTTP requests, populate caches, etc.
    */
   protected function setup (): void
   {
      // ...
   }

   /**
    * Subclass hook — runs once on dispose() if Ready.
    *
    * Default: clears the state bag.
    */
   protected function teardown (): void
   {
      $this->State->clear();
   }
}
