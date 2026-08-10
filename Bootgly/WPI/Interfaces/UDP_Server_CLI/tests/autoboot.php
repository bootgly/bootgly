<?php


use Bootgly\ACI\Tests\Suite;


return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      '1.1-connection_close_timer_release',
      '1.2-console',
      // # Peer admission before allocation (UDP-2)
      '1.3-accept_admission'
   ]
);
