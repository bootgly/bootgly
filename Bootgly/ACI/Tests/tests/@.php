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
      '0.1.2-Basic_API-return_boolean.retestable',
      '0.1.3-Basic_API-return_fallback_as_string',
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
   ]
);
