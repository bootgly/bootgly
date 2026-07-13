<?php

namespace Bootgly\WPI\Queues;

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
      // # Messenger adapter
      '1.1-messenger',
      // # HTTP loop: dispatch (request) → worker processes
      '1.2-dispatch-worker',
   ]
);
