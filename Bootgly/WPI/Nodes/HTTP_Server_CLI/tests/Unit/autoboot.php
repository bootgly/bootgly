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
      '1.19-decoder_connection_template_lifecycle',
      // # Production header-block memo (audit N3) — the live suites all
      // run in the Test environment, which bypasses the memo entirely.
      '1.20-header_scan_memo_production_replay',
      '1.21-select_descriptor_admission',
      '1.22-pretest_fail_closed',
      '1.23-header_read_visibility',
      '1.24-vary_source_consolidation',
      '1.25-router_static_serve',
      '1.26-request_fields_method_agnostic',
      '1.27-request_input_keeps_streaming_fields',
      '1.28-decoder_empty_file_input',
      // # Ordered writer ownership of reject() bytes (TCP-3)
      '1.29-reject_ordered_writer_drain',
      '1.30-connection_teardown_owners',
      '1.31-http2_stream_teardown_owners',
      '1.32-select_context_cancellation',
      '1.33-cancellation_generation',
      // # Pay-for-use teardown tombstones (L4 review finding 2)
      '1.34-ownership_lean_tombstone',
      // # Eviction through recorded locations (L4 review finding 3)
      '1.35-select_eviction_locations',
      '1.36-select_parked_watermark',
      // # Terminal stream teardown (L4 review finding 7)
      '1.37-http2_stream_close_terminality',
      // # BG-20: the idle reaper vs retained deferred work
      '1.38-ownership_check',
      '1.39-connection_expire_ownership',
      // # BG-20: a Throwable delivered at a parked Fiber's wait point
      '1.40-select_interrupt',
      // # BG-20: the documented server-wide deferral budget knob
      '1.41-configure_deferred_timeout',
      // # BG-20: prompt release of evicted generations (finally, never catch)
      '1.42-select_graveyard_reap',
      // # BG-15: the request a Response answers is readable, never writable, never shadowed
      '1.43-response_request_exposure',
   ]
);
