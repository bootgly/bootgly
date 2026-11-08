<?php

namespace Bootgly\ADI\Validators\Tests;

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
      '1.1-validators',
      '1.2-validators_extra',
      '1.3-validators_i18n',
      // # 1.0.x (M6): the MIME rule sniffs the uploaded content, confined to BOOTGLY_UPLOADS_DIR
      '1.4-validators_MIME_content',
      // # 1.2.0: JSON Schema keywords of the shipped rules (Validation\Renderer)
      '1.5-validators_describe',
      '1.6-validators_types',
   ]
);
