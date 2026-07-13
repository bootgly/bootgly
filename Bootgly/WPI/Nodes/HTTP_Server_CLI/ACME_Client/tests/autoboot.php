<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;

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
      '1.1-autotls_validation',
      '1.2-autotls_context',
      '1.3-lifecycle',
      '2.1-account_key',
      '2.2-account_thumbprint',
      '3.1-jws_structure',
      '3.2-nonces',
      '4.1-csr',
      '4.2-certificates',
      '5.1-challenges',
      '5.2-directory',
      '5.3-swaps',
      '5.4-client',
      '5.5-transport_deadline',
      '5.6-protocol_faults',
   ]
);
