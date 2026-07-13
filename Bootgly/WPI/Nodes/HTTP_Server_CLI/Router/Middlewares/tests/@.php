<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Tests;

use Bootgly\ACI\Tests\Suite;

return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      '1.1-cors',
      '1.2-cors_vary_and_restrictive_default',
      '2.1-rate_limit',
      '2.2-rate_limit_immutable_peer',
      '2.3-rate_limit_aggregation_global_sliding',
      '3.1-body_parser',
      '4.1-compression',
      '4.2-compression_status_gate',
      '5.1-etag',
      '5.2-etag_status_gate_and_rfc_match',
      '6.1-secure_headers',
      '7.1-request_id',
      '8.1-trusted_proxy',
      '9.1-csrf',
      '9.2-csrf_token_masking',
      '10.1-validator',
      '11.1-authentication',
      '11.2-authentication_remember',
      '11.3-authentication_fallback_redirect',
      '12.1-authorization'
   ]
);
