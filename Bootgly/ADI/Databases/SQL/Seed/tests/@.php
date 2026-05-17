<?php

namespace Bootgly\ADI\Databases\SQL\Seed;


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
      '11.1-seeders_files',
      '11.2-seeders_runner',
      '11.3-seeders_transactions',
   ]
);
