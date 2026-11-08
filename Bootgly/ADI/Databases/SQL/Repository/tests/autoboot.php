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
      '12.8-orm_insert_backfill',
      '12.9-orm_pagination_selection',
      '12.10-orm_pagination_page',
      '12.11-orm_pagination_cursor',
      '12.12-orm_pagination_e2e',
      '12.13-orm_pagination_serial',
      '12.14-orm_insert_key_width',
      '12.15-orm_update_provenance',
      '12.16-orm_hydrate_key_width',
      '12.17-orm_relation_empty_window',
      '12.18-orm_persistence_context',
      // # 1.1.0 (REPO-14/REPO-15): the repository's own predicates never join a
      //   caller's OR, and a caller's predicates replay in call order
      '12.19-orm_selection_guards',
      // # 1.2.0: hydration casts go through the ADI Casters loose mode; an int
      //   the range cannot hold (float, INF, NAN) is refused, never wrapped
      '12.20-orm_hydrate_casts',
   ]
);
