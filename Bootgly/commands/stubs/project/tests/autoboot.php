<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suites;

// __NAME__ test registry — this project's Suites.
//
// Each entry is a directory relative to this project's root carrying an
// `autoboot.php` that returns a Suite (entries already pointing inside a
// `tests/` folder load their own `autoboot.php` directly):
//   - 'tests/example/'  → tests/example/autoboot.php
//   - 'tests/E2E/'      → tests/E2E/autoboot.php
//
// Run this project's suites with `bootgly test` from the project directory
// (cd projects/__PATH__), one with `bootgly test <index>` and a single case
// with `bootgly test <index> <case>`. From the `projects/` directory,
// `bootgly test` runs every registered project's suites.
return new Suites(
   directories: [
      // The example suite — a running tour of the test API (Basic asserts +
      // Advanced fluent expectations); remove it when your suites take over:
      'tests/example/',
   ]
);
