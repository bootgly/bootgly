<?php

namespace Bootgly\ABI\Data\Language\Tests;

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
      '1.1-locales',
      '1.2-translate',
      '1.3-catalogs',
      '1.4-catalogs_malformed',
      '1.5-contexts',
      '1.6-catalogs_noncanonical',
   ]
);
