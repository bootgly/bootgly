<?php

namespace Bootgly\ABI\IO\FS\Dir;

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
      '1.1-construct-real_dir',

      '2.x-dynamic_get-properties-writable',
      '2.x-dynamic_get-properties-permissions',

      '3.x-dynamic_set-properties-permissions',

      '4.x-dynamic_call-methods-scan',
   ]
);
