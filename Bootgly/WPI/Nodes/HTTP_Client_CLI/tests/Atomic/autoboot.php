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
   suiteName: 'HTTP_Client_CLI Atomic',
   tests: [
      'Request/' => [
         '1.1-construct-defaults',
         '1.2-invoke-get_request',
         '1.3-invoke-post_json_body',
         '1.4-invoke-post_string_body',
         '1.5-encode-raw_http_string',
         '1.6-reset-state',
         '1.7-configure-ssl_options',
      ],
      'Request/Raw/' => [
         '2.1-header-set_get',
         '2.2-header-append',
         '2.3-header-remove',
         '2.4-header-build',
         '2.5-body-encode_raw',
         '2.6-body-encode_json',
         '2.7-body-encode_form',
      ],
      'Response/' => [
         '3.1-construct-defaults',
         '3.2-header-define_and_get',
         '3.3-header-multi_value',
         '3.4-reset-state',
      ],
      'Response/Raw/' => [
         '4.1-body-decode_json',
         '4.2-body-decode_default',
      ],
   ]
);
