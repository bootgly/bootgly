<?php

namespace Bootgly\ABI\Data\__String\Theme;

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
      '1.1-apply-open-close',
      '2.1-apply-appending-fixed',
      '3.1-select-check-list',
      '4.1-add-multiple',
      '5.1-builtins-mono-colorless',
      '6.1-add-invalid-throws',
      '7.1-escaped-seam',
   ]
);
