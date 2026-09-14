<?php

namespace Bootgly\ABI\Syntax\Imports;

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
      '1.1-analyzer-grouped-use',
      '1.2-formatter-comments',
      '1.3-analyzer-unused-imports',
      '1.4-analyzer-namespace-label',
      '1.5-analyzer-block-order',
      '1.6-analyzer-multiple-namespaces',
      '1.7-analyzer-typed-constants',
   ]
);
