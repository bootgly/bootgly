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
      '2.2-cache-handler-shared_ipc_forgery',
      '2.3-cache-handler-key_race',
      '2.4-cache-handler-key_path',

      '3.1-events',

      '4.1-cookie_security_defaults_not_downgraded',
   ]
);
