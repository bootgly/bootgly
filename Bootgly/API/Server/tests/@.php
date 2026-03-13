<?php

use Bootgly\ACI\Tests\Suite;

return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   // * Data
   suiteName: 'API\Server\Middlewares pipeline Test',
   tests: [
      '1.1-pipeline_empty_stack',
      '1.2-pipeline_single_middleware',
      '1.3-pipeline_onion_order',
      '1.4-pipeline_short_circuit',
      '1.5-pipeline_pipe_multiple',
      '1.6-pipeline_prepend_append'
   ]
);
