<?php

namespace Bootgly\ADI\Databases\SQL\Repository;


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
      '12.1-orm_model_metadata',
      '12.2-orm_repository_operations',
      '12.3-orm_hydration_scopes',
      '12.4-orm_relationship_load',
      '12.5-orm_async_execution',
      '12.6-orm_async_relationship_load',
      '12.7-orm_lazy_relationship_load',
   ]
);
