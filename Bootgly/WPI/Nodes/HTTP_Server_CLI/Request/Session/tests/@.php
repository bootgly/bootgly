<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;

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
      '1.1-cache-handler-roundtrip',
      '1.2-cache-handler-expiry',

      '2.1-cache-handler-shared',
   ]
);
