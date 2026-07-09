<?php

namespace Bootgly\ABI\Debugging;

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
      '1.1-render-cli',
      '1.2-render-html',
      '1.3-render-code-chip',
      '1.4-trace',
      '1.5-report-echo',
      '2.1-notify-seam',
      '3.1-shutdown-collect',
      '4.1-page-render',
   ]
);
