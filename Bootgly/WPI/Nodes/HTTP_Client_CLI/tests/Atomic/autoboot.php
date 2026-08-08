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
         '1.8-invoke-invalidates_encoded',
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
      '5.1-pretest-fail_closed',
      'Response/Decoders/' => [
         '6.1-decoder-content_length_consumed',
         '6.2-decoder_chunked-ownership',
         '6.3-decoder_chunked-overflow',
         '6.4-decoder_chunked-size_line',
      ],
      // ! Client-level cases the E2E harness cannot host: event-driven mode is
      //   process-wide and its request() returns the client, not a Response.
      //   Each of these forks its own origin and drives its own reactor.
      '7.1-event_driven-repeated_pair',
      '7.2-event_driven-post_bodies',
      '7.3-event_driven-two_origins',
      '7.4-follow-redirect_failed_veto',
      '7.5-event_driven-reconfigure_origin',
      '7.6-construct-unconfigured_origin',
   ]
);
