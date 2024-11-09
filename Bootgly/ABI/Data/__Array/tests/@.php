<?php

namespace Bootgly\ABI\Data\__Array;


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
      '1.x-dynamic-list',
      '1.x-dynamic-multidimensional',
      '1.x-dynamic-objects',
      // Call static
      '2.0-callstatic-search'
   ]
];
