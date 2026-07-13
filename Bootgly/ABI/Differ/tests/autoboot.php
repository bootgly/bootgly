<?php

namespace Bootgly\ABI\Differ;

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
      '1.x-line',
      '1.x-chunk',
      '1.x-diff',
      '2.x-calculator-time',
      '2.x-calculator-memory',
      '3.x-differ-basic',
      '3.x-differ-only',
      '3.x-differ-unified',
      '3.x-differ-strict',
      '3.x-differ-escaped',
      '3.x-differ-escaped-contract',
      '3.x-differ-side-by-side',
      '4.x-parser',
      '5.x-input-git-diff',
   ]
);
