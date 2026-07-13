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
      // Summary — JSON export
      '6.1.1-summary-export-json_document',
      // Provenance — packaged fallbacks + live Git state
      '6.2.1-provenance-inspect_fallbacks',
      '6.3.1-provenance-inspect_git_state',
      // Chart — native SVG rendering
      '7.1.1-chart-render-svg',
      // Report — Markdown + SVG artifacts
      '8.1.1-report-save-markdown_and_charts',
   ],
);
