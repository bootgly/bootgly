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
      'CacheIsolation/' => [
         '12.1a-prime_cache_get_alpha',
         '12.1b-different_uri_no_leak',
         '12.1c-same_uri_fresh_response',
         '12.2a-post_status_201',
         '12.2b-get_after_post_no_leak',
         '12.3a-status_404',
         '12.3b-status_200_after_404',
         '12.4a-headers_set_a',
         '12.4b-headers_set_b_no_leak',
         '12.5a-empty_body_204',
         '12.5b-body_after_empty',
      ],
      'Redirects/' => [
         '9.1-redirect_301',
         '9.2-redirect_302',
         '9.3-redirect_307_preserve_method',
         '9.4-redirect_max_exceeded',
      ],
      'Timeouts/' => [
         '10.1-response_timeout',
      ],
      'Retries/' => [
         '11.1-retry_on_failure',
      ],
   ]
);
