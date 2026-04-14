<?php

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\tests\E2E;

use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;

return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Logger::$display = Logger::DISPLAY_NONE;

      HTTP_Client_CLI::pretest($Suite);
      HTTP_Client_CLI::test(9999);

      return true;
   },
   autoInstance: false,
   autoReport: false,
   autoSummarize: false,
   exitOnFailure: true,
   // * Data
   suiteName: 'HTTP_Client_CLI E2E',
   tests: [
      'Connection/' => [
         '5.1-basic_get',
         '5.2-post_with_body',
      ],
      'Decoding/' => [
         '6.1-chunked_response',
         '6.2-content_length',
      ],
      'Headers/' => [
         '8.1-ows_parsing',
         '8.2-multi_value_set_cookie',
      ],
      'Protocol/' => [
         '7.1-100_continue',
         '7.2-informational_1xx',
         '7.3-no_body_204',
      ],
   ]
);
