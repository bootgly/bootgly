<?php

namespace Bootgly\ABI\Data\__Array;


use Bootgly\ACI\Tests\Suite;


return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      '1.x-dynamic-list',
      '1.x-dynamic-multidimensional',
      '1.x-dynamic-objects',
      // Call static
      '2.0-callstatic-search'
   ]
);
