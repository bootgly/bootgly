<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI;


use Bootgly\ACI\Tests\Suite;


require_once __DIR__ . '/fixtures/Nameserver.php';
require_once __DIR__ . '/fixtures/Harness.php';


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
      // # Units: settings, hosts, cache, order, configuration
      '1.1-settings',
      '1.2-hosts',
      '1.3-cache',
      '1.4-precedence',
      '1.5-configs',
      // # Wire: query() against the fake nameserver (blocking)
      '2.1-wire',
      '2.2-cname',
      '2.3-search',
      '2.4-tcp',
      '2.5-state',
      '3.1-reactor',
      '4.1-fork',
   ]
);
