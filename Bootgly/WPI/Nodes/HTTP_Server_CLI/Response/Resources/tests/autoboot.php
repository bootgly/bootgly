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
      '1.8-database_resource_transact',
      '1.9-database_resource_transact_nested',
      '1.10-resource_registry_lifecycle',
      '1.11-database_resource_transact_routing',
      '1.12-http_resource',
      '1.13-http_resource_dial',
      // # 1.0.x (M2): the embedded client's response cap
      '1.14-http_resource_response_cap',
      // # 1.0.x (RH-H3-residual): a wait that never comes back withdraws its operations
      '1.15-database_resource_withdraw',
      // # 1.0.x (RH-H3-residual): a KV wait that never comes back withdraws its commands
      '1.16-kv_resource_withdraw',
      // # 1.0.x (M3): redirects stay on the upstream origin by default
      '1.17-http_resource_redirect_pin',
   ]
);
