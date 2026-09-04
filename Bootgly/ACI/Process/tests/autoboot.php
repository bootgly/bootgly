<?php

namespace Bootgly\ACI\Process;

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
      '1.1-worker-events',
      '1.2-state-qualify',
      '1.3-state-lock',
      '1.4-state-safety',
      '1.5-state-demoted-cleanup',
      '1.6-state-root-handoff',
      '1.7-state-lock-handoff',
      '1.8-state-process-identity',
      '1.9-state-clean-ownership',
      '1.10-process-ownership',
      '1.11-state-lock-table',
      '1.12-states-discovery',
      '1.13-state-tapfile-sweep',
      '1.14-inits-detect',
      '1.15-service-render',
      '1.16-state-lock-seal',
   ]
);
