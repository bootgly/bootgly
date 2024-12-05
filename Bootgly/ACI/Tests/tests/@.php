<?php

namespace Bootgly\ACI\Tests;

return [
   // * Config
   'autoBoot' => __DIR__,
   'autoInstance' => true,
   'autoReport' => true,
   'autoSummarize' => true,

   'exitOnFailure' => true,
   // * Data
   'suiteName' => __NAMESPACE__,
   'tests' => [
      // Basic API
      '0.1.1-Basic_API-return_true',
      '0.1.2-Basic_API-return_boolean.retestable',
      '0.1.3-Basic_API-return_fallback_as_string',
      '0.3.1-Basic_API-yield.boolean',

      // Advanced API
      #'1.0.0-Advanced_API-asserted',
      '1.0.1-Advanced_API-assert',
      // Advanced API - Expectations/Comparators
      '1.1.1-Advanced_API-expectations-comparators-greater_than',
      '1.1.2-Advanced_API-expectations-comparators-identical',
      '1.1.3-Advanced_API-expectations-comparators-less_than',
      '1.1.4-Advanced_API-expectations-comparators-not_equal',
      // Advanced API - Expectations/Delimiters
      '1.2.1-Advanced_API-expectations-delimiters-closed_interval',
      // Advanced API - Expectations/Finders
      '1.3.1-Advanced_API-expectations-finders-contains',
      '1.3.2-Advanced_API-expectations-finders-ends_with',
      '1.3.3-Advanced_API-expectations-finders-starts_with',
      // Advanced API - Expectations/Matchers
      '1.4.1-Advanced_API-expectations-matchers-regex_match',
      '1.4.2-Advanced_API-expectations-matchers-variadic_dir_path',
      // Advanced API - Snapshots
      '1.5.0-Advanced_API-snapshots',
   ]
];
