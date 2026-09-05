<?php

namespace Bootgly\commands;

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
      '1.1-project-execute',
      '1.2-project-option-guard',
      // ! Appended last so earlier case indexes stay stable.
      '1.3-project-metadata-guard',
      '1.4-project-transfer-guard',
      '1.5-project-refresh',
      '1.6-project-wizard-name',
      '1.7-project-platform-release',
      '1.8-project-track',
      '1.9-project-import-clone',
      '1.10-project-stock',
      '1.11-project-wizard-mode',
      '2.1-setup-wrapper',
      '2.2-setup-wrapper-root-boundary',
      '2.3-setup-privilege-delegation',
      // # logs command (backlog + file follow)
      '3.1-logs-backlog',
      '3.2-logs-follow-files',
      // # console-project registry identity (start registers, TUI adopts)
      '4.1-project-start-cli-state',
      // # logs live lane (project scope delegation + instance tiebreaker)
      '4.2-project-logs-scope',
      '4.3-logs-instances',
      // # project-scoped schedule (mount + delegate — no server started)
      '5.1-project-schedule',
      // # record instance stamp of a bare TUI (appended last — earlier indexes stay stable)
      '4.4-tui-instance-stamp',
      '6.1-projects-show',
      '6.2-projects-list',
      '6.3-projects-show-fit',
      '7.1-project-startup',
      // # kit upgrade / downgrade (fixture kits under the temp dir)
      '8.1-kit-list',
      '8.2-kit-move',
      '8.3-kit-guards',
      '8.4-kit-template',
      '8.5-kit-running',
      '8.6-kit-swap-purity',
      '8.7-kit-partial',
      '8.8-kit-boot',
      '8.9-kit-container',
      // # a refused create never bootstraps the kit (appended last — earlier
      //   indexes stay stable)
      '1.12-project-refusal-purity',
      // # project stop/restart reporting over tombstones and unverifiable state
      '9.1-project-stop-report',
   ]
);
