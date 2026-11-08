<?php

namespace Bootgly\ADI\Casters\Tests;

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
      // # 1.2.0: one scalar casting vocabulary, strictness named per caller
      '1.1-casters_strict',
      '1.2-casters_loose',
      '1.3-casters_datetime',
   ]
);
