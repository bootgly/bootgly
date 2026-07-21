<?php

namespace Bootgly\ABI\Code\__String\Markdown;

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
      '1.1-blocks-headings',
      '1.2-blocks-paragraphs',
      '1.3-blocks-fences',
      '1.4-blocks-quotes',
      '1.5-blocks-lists',
      '1.6-blocks-tables',
      '1.7-blocks-rules',
      '2.1-inlines-emphasis',
      '2.2-inlines-code',
      '2.3-inlines-links',
      '3.1-edges-inputs',
   ]
);
