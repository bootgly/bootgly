<?php

namespace Bootgly\CLI\UI\Components;

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
      '1.1-dialog-confirm',
      '1.2-dialog-alert',
      '1.3-dialog-prompt',
      '2.1-question-ask',
      '2.2-question-validation',
   ]
);
