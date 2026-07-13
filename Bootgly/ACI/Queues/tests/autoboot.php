<?php

namespace Bootgly\ACI\Queues;

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
      // # Contract + File driver
      '1.1-enqueue-reserve-complete',
      '1.2-fifo-order',
      '1.3-serialization',
      '1.4-delayed-availability',
      '1.5-release-retry',
      '1.6-bury-deadletter',
      '1.7-recover-reaper',
      '1.8-concurrency-claim',
      '1.9-dispatch-event',
      // # Backoff
      '2.1-backoff-delay',
      // # Worker
      '3.1-worker-processes',
      '3.2-worker-retry',
      '3.3-worker-bury',
      '3.4-worker-idle',
      // # Redis driver
      '4.1-redis-driver',
      // # Security
      '6.1-security-deserialization',
   ]
);
