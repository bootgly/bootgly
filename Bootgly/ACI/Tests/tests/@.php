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
      // Comparing
      '_0.1.1-Simple_API-return-true',
      '_0.1.2-Simple_API-return-string',
      '_0.2.1-Simple_API-yield.boolean',

      '1.1-comparing-identical',
      '1.2-comparing-between',

      // Snapshot
      '2.0-snapshots',
   ]
];
