<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suite;

// Example suite — a running tour of the Bootgly test API.
//
// A Suite lists its test files (below, without the `.test.php` extension);
// each file returns a Specification with the actual assertions. Remove this
// example (and its registry entry in `tests/autoboot.php`) when your own
// suites take over.
//
// Docs: https://docs.bootgly.com/testing/about/testing/overview/
return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   // * Data
   suiteName: 'Example',
   tests: [
      '1.1-basic-assertions',
      '1.2-advanced-expectations',
   ]
);
