<?php

namespace Bootgly\ADI\Databases\SQL;

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
      '1.1-query-events',
      '1.2-transaction-events',
      '2.1-transact-sqlite',
      '2.2-transact-wire',
      '2.3-transact-interrupted',
      '2.4-transact-replicas',
      '2.5-transaction-begin_in_flight',
      '2.6-transact-postgresql_live',
      '2.7-transact-mysql_live',
   ]
);
