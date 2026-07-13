<?php

namespace Bootgly\ADI\Databases\SQL\Builder;


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
      '9.1-sql_builder_select',
      '9.2-sql_builder_mutations',
      '9.3-sql_builder_operation',
      '9.4-sql_builder_transaction',
      '9.5-sql_builder_combinations',
      '9.6-sql_builder_essentials',
      '9.7-sql_builder_dialects',
      '9.8-sql_builder_subqueries',
      '9.9-sql_builder_cte',
      '9.10-sql_builder_upsert_ordering',
   ]
);
