<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\RPC\JSON;


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
      '1.1-json-specification',
      '1.2-json-batch-limit',
      '1.3-json-fault',
      '1.4-json-internal-errors',
      '1.5-json-ids-params',
      '1.6-json-execute',
      '1.7-json-concurrency',
      '1.8-json-context',
      '1.9-json-error-classes',
   ]
);
