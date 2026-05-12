<?php

namespace Bootgly\ADI\Database;


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
      '1.1-construct-default_config',
      '2.1-query-operation',
      '2.2-operation-timeout',
      '3.1-protocols-postgresql_repository',
      '3.2-postgresql_encoder',
      '3.3-postgresql_decoder',
      '3.4-postgresql_operation_stream',
      '3.5-connection-non_blocking_connect',
      '4.1-pool-reuses_connection',
      '4.2-pool-max_pending',
      '4.3-pool-created_bookkeeping',
      '5.1-postgresql_authentication_md5',
      '5.2-postgresql_authentication_scram',
      '5.3-postgresql_scram_state_machine',
      '6.1-postgresql_tls_prefer',
      '6.2-postgresql_tls_require',
      '6.3-postgresql_tls_accept',
      '7.1-postgresql_extended_encoder',
      '7.2-postgresql_extended_operation',
      '7.3-postgresql_prepared_cache',
      '7.4-postgresql_prepared_cache_lru',
      '7.5-postgresql_error_cleanup',
      '7.6-postgresql_result_types',
      '7.7-postgresql_parameter_oids',
      '7.8-postgresql_error_paths',
      '7.9-postgresql_cancel_request',
      '7.10-postgresql_result_advanced_types',
      '7.11-postgresql_statement_describe',
      '7.12-postgresql_pipeline',
      '7.13-postgresql_pipeline_release',
   ]
);
