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


/**
 * Test double — behavior-backed substitute with its own working state.
 *
 * Fakes are direct implementations for collaborators whose behavior is easier
 * to model as state than as per-method canned returns. They do not generate
 * proxies and do not record calls by default; use Mock or Spy for that.
 */
abstract class Fake implements Doubling
{
   /**
    * Reset fake state. Stateless fakes may keep the default no-op behavior.
    */
   public function reset (): static
   {
      return $this;
   }
}
