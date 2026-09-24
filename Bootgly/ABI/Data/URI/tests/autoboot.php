<?php

namespace Bootgly\ABI\Data\URI\Tests;

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
      '1.1-uri-grammar',
      '1.2-uri-references-normal',
      '1.3-uri-references-abnormal',
      '1.4-uri-references-hostile',
      '1.5-uri-reduce-linear',
   ]
);
