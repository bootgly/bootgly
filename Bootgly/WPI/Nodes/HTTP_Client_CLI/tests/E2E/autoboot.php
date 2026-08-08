<?php

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI\tests\E2E;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;

return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

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
         '6.3-close_delimited',
         '6.4-truncated_content_length',
         '6.5-truncated_chunked',
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
         '9.5-cross_origin_credentials_stripped',
         '9.6-same_origin_credentials_kept',
         '9.7-cross_origin_restores_client',
         '9.8-redirect_failed_break',
         '9.9-retry_stays_on_leg_origin',
      ],
      'Timeouts/' => [
         '10.1-response_timeout',
         '10.2-keepalive_batch_windows',
         '10.3-redirect_leg_window',
      ],
      'Retries/' => [
         '11.1-retry_on_failure',
         '11.2-retry_backoff_timing',
         '11.3-retry_budget',
         '11.4-retry_on_status',
         '11.5-retry_after_date',
         '11.6-retry_post_blocked',
      ],
      'Pool/' => [
         '13.1-pool_reuse',
         '13.2-pool_queue',
         '13.3-pool_stale',
         '13.4-pool_warm',
      ],
      'LargeBody/' => [
         '14.1-content_length_split_reads',
         '14.2-content_length_multi_read',
         '14.3-content_length_keepalive_split',
      ],
      'ChunkedStream/' => [
         '15.1-chunked_paced_writes',
         '15.2-chunked_multi_read',
      ],
      'SplitHead/' => [
         '16.1-split_header_block',
      ],
      'Overflow/' => [
         '17.1-chunked_over_cap_unbounded',
         '17.2-declared_oversize_with_headers',
         '17.3-declared_oversize_later_read',
         '17.4-wire_cap_content_length',
         '17.5-declared_oversize_keepalive',
         '17.6-declared_oversize_keepalive_later_read',
         '17.7-malformed_chunk_size',
         '17.8-negative_chunk_size',
      ],
      // ! Last on purpose: these build a second client, which replaces the
      //   process-wide reactor. Nothing after them may rely on the previous one.
      'Isolation/' => [
         '18.1-second_client_keeps_host',
         '18.2-second_client_keeps_redirect_base',
      ],
   ]
);
