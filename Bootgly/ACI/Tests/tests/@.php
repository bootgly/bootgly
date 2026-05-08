<?php

namespace Bootgly\ACI\Tests;

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
      // Basic API
      '0.1.1-Basic_API-return_true',
      #'0.1.2-Basic_API-return_boolean.retestable',
      #'0.1.3-Basic_API-return_fallback_as_string',
      '0.3.1-Basic_API-yield.boolean',

      // Advanced API
      '1.0.0-Advanced_API-asserted',
      '1.0.1-Advanced_API-assert',
      // Advanced API - Expectations
      '1.1.1-Advanced_API-expectations-equal',
      '1.1.2-Advanced_API-expectations-greater_than',
      '1.1.3-Advanced_API-expectations-greater_than_or_equal',
      '1.1.4-Advanced_API-expectations-identical',
      '1.1.5-Advanced_API-expectations-less_than',
      '1.1.6-Advanced_API-expectations-less_than_or_equal',
      '1.1.7-Advanced_API-expectations-not_equal',
      '1.1.8-Advanced_API-expectations-not_identical',
      // Advanced API - Expectations/Behaviors
      '1.2.1-Advanced_API-expectations-behaviors-types',
      #'1.2.2-Advanced_API-expectations-behaviors-values',
      // Advanced API - Expectations/Delimiters
      '1.3.1-Advanced_API-expectations-delimiters-closed_interval',
      // Advanced API - Expectations/Finders
      '1.4.1-Advanced_API-expectations-finders-contains',
      '1.4.2-Advanced_API-expectations-finders-ends_with',
      '1.4.3-Advanced_API-expectations-finders-starts_with',
      // Advanced API - Expectations/Matchers
      '1.5.1-Advanced_API-expectations-matchers-regex_match',
      '1.5.2-Advanced_API-expectations-matchers-variadic_dir_path',
      // Advanced API - Expectations/Throwers
      '1.6.1-Advanced_API-expectations-throwers-exception',
      // Advanced API - Expectations/Waiters
      '1.7.1-Advanced_API-expectations-waiters-timeout',
      // Advanced API - Snapshots
      '2.0.1-Advanced_API-snapshots',

      // Fixture
      '3.1.1-Fixture-prepare_dispose_lifecycle',
      '3.1.2-Fixture-state_bag_reset',
      '3.1.3-Fixture-failed_setup_keeps_pristine',
      '3.1.4-Fixture-unexpected_throw_disposes',
      '3.1.5-Fixture-injection_and_suite_propagation',
      '3.1.6-Fixture-priority_and_signature_injection',

      // Mock
      '4.1.1-Mock-stub_and_verify',
      '4.1.2-Mock-stub_throw',
      '4.2.1-Mock-proxy_by_reference_argument',
      '4.2.2-Spy-proxy_default_self_constant',
      '4.2.3-Mock-proxy_static_return',
      '4.2.4-Mock-proxy_reference_return_guard',

      // Spy
      '5.1.1-Spy-wrap_and_record',

      // Doubles
      '5.2.1-Doubles-registry_reset_clear',

      // Faker
      '6.1.1-Faker-deterministic_seed',
      '6.1.2-Fakers-trait_dispatch',

      // Coverage
      '7.1.1-Coverage-nothing_driver_smoke',
      '7.1.2-Coverage-detect_xdebug_mode_guard',
      '7.1.3-Coverage-canonical_path_merge',
      '7.1.4-Coverage-sut_target_filter',
      '7.1.5-Coverage-text_report_diff_flag',
      '7.1.6-Coverage-text_report_diff_semantics',
      '7.1.7-Coverage-text_report_diff_sut_scope',
      '7.1.8-Coverage-text_report_diff_missing_file',
      '7.1.9-Coverage-scope_filter_keeps_framework_sources',
      '7.1.10-Coverage-report_hardening',
      '7.2.1-Coverage-Native_analyzer',
      '7.2.2-Coverage-Native_compiler',
      '7.2.3-Coverage-Native_universe',
      '7.2.4-Coverage-Native_lifecycle',
      '7.2.5-Coverage-Native_parity_projection',
      '7.2.6-Coverage-Native_route',
      '7.2.7-Coverage-Native_autoboot',
      '7.2.8-Coverage-Native_bootgly_path_suite',
      '7.2.9-Coverage-Native_bootgly_pipe_suite',
      '7.2.10-Coverage-Native_bootgly_late_suites',
      '7.2.11-Coverage-Native_invalid_suite_index',
   ]
);
