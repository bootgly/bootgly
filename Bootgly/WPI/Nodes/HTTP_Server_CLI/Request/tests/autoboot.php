<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;

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
      '1.1-events',
      '1.2-attributes_bag',
      '1.3-assume_scrub_parity',
      '1.4-reset_scrub_parity',
   ]
);
