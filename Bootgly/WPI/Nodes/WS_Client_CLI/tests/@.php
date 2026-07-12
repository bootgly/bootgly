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
      '1.1-handshake',
      '2.1-frame',
      '2.2-utf8',
      '3.1-request',
      '4.1-multiclient',
      '5.1-handshake_timeout',
      '5.2-reconnect_budget',
      '5.3-backpressured_send',
      '5.4-monotonic_timer',
      '5.5-close_drain_timeout'
   ]
);
