<?php

namespace Bootgly\ACI\VCS;

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
      '1.1-git-engine',
      '1.2-tags-remotes',
      '1.3-submodules',
   ]
);
