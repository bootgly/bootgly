<?php

namespace Bootgly\ABI\Resources\Cache;

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
      '1.1-store-fetch',
      '1.2-check-delete',
      '1.3-ttl-expiry',
      '1.4-increment-decrement',
      '1.5-tags-invalidate',
      '1.6-clear-purge',
      '1.7-resolve',

      '2.1-apcu-driver',

      '4.1-redis-driver',

      '5.1-shared-driver',
      '5.2-shared-cross-worker',
   ]
);
