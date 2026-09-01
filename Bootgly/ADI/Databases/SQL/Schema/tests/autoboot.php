<?php

namespace Bootgly\ADI\Databases\SQL\Schema;


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
      '10.1-schema_builder_ddl',
      '10.2-schema_migrations',
      '10.3-schema_repository_lock',
      '10.4-schema_sync',
      '10.5-migration-events',
      '10.6-schema_sqlite_transactions',
      '10.7-schema_mysql_lock',
      '10.8-schema_mysql_column_change',
      '10.9-schema_non_finite_defaults',
      '10.10-schema_timestamptz',
   ]
);
