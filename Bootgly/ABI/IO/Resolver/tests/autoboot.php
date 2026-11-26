<?php

namespace Bootgly\ABI\IO\Resolver;

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
      '1.1-literals',
      '1.2-lookup',
      '1.3-fallback',
      '1.4-refusals',
      '1.5-sources',
   ]
);
