<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests;


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
      '1.1-database_resource',
      '1.2-kv_resource',
      '1.3-database_resource_provide',
      '1.4-kv_resource_provide',
      '1.5-xml_resource',
      '1.6-negotiation_resource',
      '1.7-database_resource_paginate',
   ]
);
