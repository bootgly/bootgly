<?php

namespace Bootgly\API\Environment;

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
      '1.1-build-detect',
      '1.2-build-inspect',
      // # Docker build-context secret boundary (security audit H5)
      '1.3-docker-context-secrets',
   ]
);
