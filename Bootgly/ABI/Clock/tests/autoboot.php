<?php

namespace Bootgly\ABI\Clock\Tests;

use Bootgly\ACI\Tests\Suite;

return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   // * Data
   suiteName: __NAMESPACE__,
   tests: [
      '1.1-clock-readings',
      '1.2-stopwatch-measure',
      '1.3-stopwatch-system',
      '1.4-adapt-closure',
   ]
);
