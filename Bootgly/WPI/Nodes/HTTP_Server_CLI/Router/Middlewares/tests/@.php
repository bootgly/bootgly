<?php

use Bootgly\ACI\Tests\Suite;

return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   // * Data
   suiteName: 'WPI\Nodes\HTTP_Server_CLI\Router\Middlewares',
   tests: [
      '1.1-cors',
      '2.1-rate_limit',
      '3.1-body_parser',
      '4.1-compression',
      '5.1-etag',
      '6.1-secure_headers',
      '7.1-request_id',
      '8.1-trusted_proxy'
   ]
);
