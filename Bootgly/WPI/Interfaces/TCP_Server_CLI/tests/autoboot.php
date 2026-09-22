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
      // # The root ownership handoff never follows a link (store/tap)
      '1.5-store-handoff',
      '1.6-store-root-handoff',
      '1.7-store-before-records',
      '1.8-inherit-launcher-hold',
      '1.9-privilege-source-pins',
      // # The Daemon fallback sink yields to a sink registered before start() (LOGS-10)
      '1.10-fallback-yields',
   ]
);
