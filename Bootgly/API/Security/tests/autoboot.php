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
      '1.8-authorization',
      '1.9-authorization_rbac',
      '1.10-authorization_rbac_cache',
      '1.11-jwt_vault_storage',
      '1.12-encrypter_roundtrip',
      '1.13-encrypter_tamper',
      '1.14-encrypter_aad',
      '1.15-encrypter_keyring',
      '1.16-password_hash',
      '1.17-password_policy',
      '1.18-tokens_lifecycle',
      '1.19-trust_lifecycle',
      '1.20-users_credentials',
      '1.21-auth_flows',
   ]
);
