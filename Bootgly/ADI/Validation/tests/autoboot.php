<?php

namespace Bootgly\ADI\Validation\Tests;

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
      '1.2-validation_custom_rule',
      // # 1.2.0: nested paths, wildcards, the expansion bound and the JSON Schema export
      '1.3-validation_flat_parity',
      '1.4-validation_paths',
      '1.5-validation_nested',
      '1.6-validation_limit',
      '1.7-validation_renderer',
   ]
);
