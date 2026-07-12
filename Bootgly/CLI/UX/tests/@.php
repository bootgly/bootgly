<?php

namespace Bootgly\CLI\UX;

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
      '1.1-form-fields',
      '1.2-form-sequence',
      '1.3-form-revert',
      '1.4-form-validation',
      '2.1-prompt-flow',
      '2.2-prompt-interruption',
      '2.3-prompt-mouse',
      '2.4-prompt-native',
      '3.1-tabs-add',
      '3.2-tabs-bar',
      '3.3-tabs-switch',
      '3.4-tabs-switching',
   ]
);
