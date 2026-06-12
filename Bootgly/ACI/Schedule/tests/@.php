<?php

namespace Bootgly\ACI\Schedule;

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
      // # Cron
      '1.1-cron-wildcard',
      '1.2-cron-time',
      '1.3-cron-step',
      '1.4-cron-range-list',
      '1.5-cron-advance',
      '1.6-cron-dom-dow',
      // # Frequencies
      '2.1-frequencies-resolve',
      // # Schedule + Job
      '3.1-schedule-declaration',
      '3.2-schedule-tick',
      '3.3-schedule-run',
      // # Lock + State + catch-up
      '4.1-lock-overlap',
      '4.2-state-persistence',
      '4.3-catchup-policy',
      // # Domain events
      '5.1-events-started-finished',
      '5.2-events-failed-survives',
      '5.3-events-skipped-overlap',
   ]
);
