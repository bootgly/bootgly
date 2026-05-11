<?php

namespace Bootgly\API\Security\Tests;

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
      '1.1-jwt_hs256',
      '1.2-jwt_keyset',
      '1.3-jwt_rs256',
      '1.4-jwt_jwks',
      '1.5-jwt_claim_policies',
      '1.6-jwt_jwks_remote',
      '1.7-jwt_lifecycle',
   ]
);
