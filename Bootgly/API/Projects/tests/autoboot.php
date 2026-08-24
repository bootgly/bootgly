<?php

namespace Bootgly\API\Projects;

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
      '1.1-validate',
      '1.2-encode',
      '1.3-filter',
      '2.1-register',
      '2.2-generate',
      '2.3-import',
      '2.4-project-exportable',
      // ! Appended last so earlier case indexes stay stable.
      '2.5-emit',
      '2.6-vet',
      '2.7-fill',
      '3.1-index',
      '3.2-load',
   ]
);
