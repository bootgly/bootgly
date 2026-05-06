<?php

namespace Bootgly\API\Environment\Configs;

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
      '1.x-config_construct',
      '1.x-config_bind',
      '1.x-config_types',
      '1.x-config_required',

      '2.x-scopes_registry',

      '3.x-configs_default_value',
      '3.x-configs_env_resolution',
      '3.x-configs_env_isolation',
      '3.x-configs_env_policy',
      '3.x-configs_traversal_guard',
      '3.x-configs_lazy_loading',
      '3.x-configs_navigation',

      '4.x-project_configs_overlay',
   ]
);
