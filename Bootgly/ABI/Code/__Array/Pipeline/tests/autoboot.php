<?php

namespace Bootgly\ABI\Code\__Array\Pipeline;


use Bootgly\ACI\Tests\Suite;


return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      // Stages
      '1.0-map',
      '1.1-filter',
      // Terminals
      '2.0-collect',
      '2.1-apply',
      '2.2-find',
      '2.3-check',
      '2.4-count',
      '2.5-reduce',
      // Contract
      '3.0-equivalence'
   ]
);
