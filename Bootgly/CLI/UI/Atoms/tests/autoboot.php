<?php

namespace Bootgly\CLI\UI\Atoms;

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
      '1.1-text-effects',

      '2.1-highlighter-render',
      '2.2-highlighter-plain',
      '2.3-highlighter-mark',
   ]
);
