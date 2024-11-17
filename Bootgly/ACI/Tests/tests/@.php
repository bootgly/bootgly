<?php

namespace Bootgly\ACI\Tests;

return [
   // * Config
   'autoBoot' => __DIR__,
   'autoInstance' => true,
   'autoReport' => true,
   'autoSummarize' => true,

   'exitOnFailure' => true,
   // * Data
   'suiteName' => __NAMESPACE__,
   'tests' => [
      // Basic API
      '0.1.1-Basic_API-return_true',
      '0.1.2-Basic_API-return_boolean.retestable',
      '0.1.3-Basic_API-return_fallback_as_string',
      '0.3.1-Basic_API-yield.boolean',

      // Advanced API
      #'1.0.0-Advanced_API-asserted',
      // Advanced API - Comparators
      '1.1.1-Advanced_API-comparing-greater_than',
      '1.1.2-Advanced_API-comparing-identical',
      '1.1.3-Advanced_API-comparing-less_than',
      // Advanced API - Expectations
      '1.2.0-Advanced_API-comparing-between',
      // Advanced API - Snapshot
      '1.3.0-Advanced_API-snapshots',
   ]
];
