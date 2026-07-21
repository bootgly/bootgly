<?php

namespace Bootgly\ABI\Code\__String;

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
      '1.x-dynamic-props-length',
      '1.x-dynamic-props-lowercase',
      '1.x-dynamic-props-pascalcase',
      '2.x-dynamic-methods-pad',
      '2.x-dynamic-methods-search',
      '2.x-dynamic-methods-wrap',
      '3.x-static-methods-mapping',
   ]
);
