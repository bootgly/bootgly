<?php

namespace Bootgly\ABI\Data\RESP;

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
      '1.1-encode-command',
      '1.2-decode-simple-types',
      '1.3-decode-bulk-and-array',
      '1.4-decode-streamed-partial',
      '1.5-decode-resp3-types',
      '1.6-decode-multiple-and-reset',
   ]
);
