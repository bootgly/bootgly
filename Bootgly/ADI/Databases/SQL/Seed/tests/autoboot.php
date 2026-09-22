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
      '11.4-seeders_composition',
      '11.5-seeders_resync_postgresql_live',
      '11.6-seeders_resync_sqlite',
      '11.7-seeders_resync_mysql_live',
      '11.8-seeders_resync_runner',
   ]
);
