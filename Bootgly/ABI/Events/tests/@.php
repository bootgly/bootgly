<?php

namespace Bootgly\ABI\Events;

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
      '1.1-listen-emit',
      '1.2-priority-order',
      '1.3-propagation-stop',
      '1.4-no-listeners-noop',
      '1.5-listener-object',
      '1.6-event-isolation',
   ]
);
