<?php

namespace Bootgly\ABI\Debugging\Data\Vars;

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
      '1.1-dump-scalars',
      '1.2-dump-arrays',
      '1.3-dump-objects',
      '1.4-dump-hooks',
      '1.5-dump-specials',
      '1.6-dump-circular',
      '1.7-dump-caps',
      '2.1-dump-themes',
      '3.1-vars-delegate',
   ]
);
