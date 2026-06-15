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
      '2.1-rate_limit',
      '2.2-rate_limit_immutable_peer',
      '3.1-body_parser',
      '4.1-compression',
      '5.1-etag',
      '6.1-secure_headers',
      '7.1-request_id',
      '8.1-trusted_proxy',
      '9.1-csrf',
      '10.1-validator',
      '11.1-authentication',
      '12.1-authorization'
   ]
);
