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
      '_0.1.1-Basic_API-return_true',
      '_0.1.2-Basic_API-return_boolean.retestable',
      '_0.1.3-Basic_API-return_fallback_as_string',
      '_0.3.1-Basic_API-yield.boolean',

      // Advanced API
      '1.1.1-comparing-greater_than',
      '1.1.2-comparing-identical',
      '1.2-comparing-between',
      // Advanced API - Snapshot
      '3.0-snapshots',
   ]
];
