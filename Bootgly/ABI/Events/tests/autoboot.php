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
      '1.7-listener-isolation',

      '2.1-selector-read',
      '2.2-selector-write',
      '2.3-selector-deadline',
      '2.4-selector-poll',
      '2.5-selector-indefinite',
      '2.6-selector-interruption',
      '2.7-selector-forget',
      '2.8-selector-payloads',
      '2.9-selector-refusals',
      '2.10-selector-bound',
   ]
);
