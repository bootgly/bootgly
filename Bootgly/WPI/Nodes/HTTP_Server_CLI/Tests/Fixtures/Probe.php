<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fixtures;


use Bootgly\ACI\Tests\Fixture;


/**
 * Generic state-bag fixture for E2E HTTP tests.
 *
 * Replaces ad-hoc `$probe = []` arrays captured by reference across
 * `request:` / `response:` / `test:` closures. The fixture's State
 * exposes a typed bag with deterministic reset between cases.
 *
 * Usage:
 *   $Probe = new Probe(['authorization' => null]);
 *   return new Specification(
 *      Fixture:  $Probe,
 *      request:  fn () => …$Probe->State->update('authorization', …)…,
 *      response: fn ($R, $W) use ($Probe) { … },
 *      test:     fn (string $r) use ($Probe) =>
 *                   $Probe->fetch('authorization') === 'Bearer …',
 *   );
 */
final class Probe extends Fixture
{
   // Inherits prepare()/dispose()/reset()/fetch()/State from Fixture.
   // setup() seeds $State->bag from the constructor's $bag; teardown() clears it.
   // No additional logic needed — the base class handles the full lifecycle.
}
