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
   ]
);
