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
   ]
);
