<?php

namespace Bootgly\ABI\Syntax\Lint;

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
      '1.1-glob-match',
      '1.2-exemption-cover',
      '1.3-lint-config',
      '1.4-lint-discover-load',
      '1.5-scope-find',
   ]
);
