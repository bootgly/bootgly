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
      '1.17-state-lock-holders',
      // # 1.1.x (v1.1.0 worker recycling): recover() returns the wait status
      '1.18-process-recover-status',
      // # 1.1.x (v1.1.0 worker recycling): send() reaches the retirees too
      '1.19-signals-send-retirees',
      // # 1.1.1: an unsafe state directory names the refusing check
      '1.20-state-protect-reasons',
      // # 1.3.0: the storage directory from the environment, and the Docker entrypoint that prepares it
      '1.21-storage-environment',
      '1.22-storage-entrypoint',
      '1.23-storage-owners',
   ]
);
