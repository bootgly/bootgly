<?php


use Bootgly\ACI\Tests\Suite;


return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      '1.1-sse_open_dead_connection',
      '1.2-timer_fork_hygiene',
      '1.3-response_code_contract',
      '1.4-connection_close_timer_release',
      '1.5-header_queued_clean_isolation',
      '1.6-stash_cookie_and_acme_guards',
      '1.7-router_dynamic_classification',
      '1.8-header_preset_transitions',
      '1.9-route_cache_key_vary_policy',
      '1.10-deferred_write_pipeline_retention',
      '1.11-pipeline_zero_consumed_guard',
      '1.12-decoder_waiting_request_binding',
      '1.13-decoder_owned_request_isolation',
      '1.14-decoder_cache_pipelined_batch_guard',
      '1.15-fragmented_head_reassembly',
      '1.16-decoder_cache_streaming_store_guard',
      '1.17-owned_request_reset_matrix',
      '1.18-connection_decoder_dispatch_invariant',
      '1.19-decoder_connection_template_lifecycle'
   ]
);
