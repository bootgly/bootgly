<?php

namespace Bootgly\ACI\Tests\Benchmark;

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
      // Options — schema load/validate
      '1.1.1-options-load-valid_schema',
      '1.2.1-options-load-invalid_schemas',
      // Options — sweep expansion
      '2.1.1-options-expand-sweep_syntax',
      '2.2.1-options-expand-invalid_values',
      // Options — CLI resolution + help
      '3.1.1-options-parse-values_and_rounds',
      '3.2.1-options-render-help_lines',
      // Configs — global output/format/results
      '4.1.1-configs-parse-output_format_results',
      // Runner — round application
      '5.1.1-runner-apply-server_workers',
      // Summary — clear configuration banner
      '5.2.1-summary-banner-clear_sections',
      // Summary — JSON export
      '6.1.1-summary-export-json_document',
      // Provenance — packaged fallbacks + live Git state
      '6.2.1-provenance-inspect_fallbacks',
      '6.3.1-provenance-inspect_git_state',
      '6.4.1-provenance-propagate_marks',
      // HTTP — exact per-connection request/response accounting
      '6.5.1-http_tracker-account_responses',
      // Artifacts — exclusive workspaces, atomic writes and child channels
      '6.6.1-artifacts-isolate_atomic',
      '6.7.1-child-capture_channels',
      '6.8.1-command-supervise-json_document',
      '6.9.1-profiler-report-isolate_run',
      '6.10.1-runtime-export-redact_directives',
      '6.11.1-outcome-check-reportable_results',
      '6.12.1-command-reject-empty_selection',
      '6.13.1-manifest-detect-unpublished_staging',
      '6.14.1-code_runner-enforce_timeout',
      '6.15.1-manifest-redact-untrusted_argv',
      // Chart — native SVG rendering
      '7.1.1-chart-render-svg',
      // Report — Markdown + SVG artifacts
      '8.1.1-report-save-markdown_and_charts',
   ],
);
