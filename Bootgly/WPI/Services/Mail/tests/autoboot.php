<?php

namespace Bootgly\WPI\Services\Mail;

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
      '1.1-messenger',
      // # 1.4.0: secrets stay out of debug dumps and exception traces
      '1.2-boot_trace_redaction',
   ]
);
