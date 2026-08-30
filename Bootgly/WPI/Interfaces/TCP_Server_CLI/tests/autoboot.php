<?php

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;

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
      // # Live-log tap hub (IMP-7)
      '1.1-tap-attach',
      '1.2-tap-backpressure',
      '1.3-tap-fork-hygiene',
      '1.4-server-log-command',
   ]
);
