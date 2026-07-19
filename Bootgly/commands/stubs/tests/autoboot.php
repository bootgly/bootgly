<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suites;

// Workspace test registry — `bootgly test` runs the Suites listed here.
//
// Each entry is a directory relative to this workspace root carrying a
// `tests/autoboot.php` file that returns a Suite (entries already pointing
// inside a `tests/` folder load their own `autoboot.php` directly):
//   - 'projects/App/'           → projects/App/tests/autoboot.php
//   - 'projects/App/tests/E2E/' → projects/App/tests/E2E/autoboot.php
//
// Run all suites with `bootgly test`, one with `bootgly test <index>` and a
// single case with `bootgly test <index> <case>`.
return new Suites(
   directories: [
      // The example suite — a running tour of the test API (Basic asserts +
      // Advanced fluent expectations); remove it when your suites take over:
      'tests/example/',
      // Register your project suites here — e.g.:
      // 'projects/App/',
   ]
);
