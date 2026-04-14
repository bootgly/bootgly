<?php

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\tests\E2E_SSL;

use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;

return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Logger::$display = Logger::DISPLAY_NONE;

      HTTP_Client_CLI::pretest($Suite, 'E2E_SSL');
      HTTP_Client_CLI::test(9998, ssl: [
         'local_cert' => __DIR__ . '/localhost.cert.pem',
         'local_pk'   => __DIR__ . '/localhost.key.pem',
      ]);

      return true;
   },
   autoInstance: false,
   autoReport: false,
   autoSummarize: false,
   exitOnFailure: true,
   // * Data
   suiteName: 'HTTP_Client_CLI E2E (SSL)',
   tests: [
      '9.1-basic_get_over_tls',
      '9.2-post_json_over_tls',
      '9.3-chunked_over_tls',
   ]
);
