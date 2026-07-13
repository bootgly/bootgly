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
      '1.1-connect_bounded',
      '2.1-pool_lifecycle',
      '2.2-pool_capacity',
      '2.3-pool_health'
   ]
);
