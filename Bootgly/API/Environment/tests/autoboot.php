<?php

namespace Bootgly\API\Environment;

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
      '1.1-build-detect',
      '1.2-build-inspect',
      // # Docker build-context boundary (security audit H5) — the checkout
      //   case runs in any container; the secrets case needs a stamped build
      //   and SKIPS visibly without one
      '1.3-docker-context-checkout',
      '1.4-docker-context-secrets',
      // # No certificate or private key but the localhost fixtures (INFRA-1)
      '1.5-tracked-certificates',
      // # Where the process runs: the image's variable or a runtime marker
      '1.6-container-detect',
   ]
);
