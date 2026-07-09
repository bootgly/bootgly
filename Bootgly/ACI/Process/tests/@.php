<?php

namespace Bootgly\ACI\Process;

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
      '1.1-worker-events',
      '1.2-state-qualify',
      '1.3-state-lock',
   ]
);
