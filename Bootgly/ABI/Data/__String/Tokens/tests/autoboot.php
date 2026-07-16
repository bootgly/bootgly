<?php

namespace Bootgly\ABI\Data\__String\Tokens;

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
      '1.1-tokenize-groups',
      '1.2-tokenize-lines',
      '1.3-tokenize-edges',
      '2.1-highlight-gutter',
      '2.2-highlight-mark',
      '2.3-highlight-gutterless',
      '2.4-highlight-sniff',
      '2.5-highlight-sources',
      '2.6-highlight-themes',
   ]
);
