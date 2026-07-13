<?php

namespace Bootgly\ABI\Templates\Template;


use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite;


return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   testables: [new Template('')],
   // * Data
   suiteName: __NAMESPACE__,
   tests: [
      '0.0.1-render-template_file',
      '1.0.0-render-php_code',
      '1.1.1-render-outputs-1',
      '2.1.1-render-loops-for',
      '2.2.1-render-loops-while',
      '2.3.1.1-render-loops-foreach',
      '2.3.1.2-render-loops-foreach-break',
      '2.3.1.3-render-loops-foreach-continue',
      '2.3.2-render-loops-foreach_with_metavar',
      '2.3.3-render-loops-nested_foreachs_with_metavar',
      '3.1.1-render-conditionals-singly_ifs',
      '3.1.2.1-render-conditionals-short_conditional_ifs',
      '3.1.2.2-render-conditionals-short_conditional_ifs',
      '3.2.1-render-conditionals-switch',
      '0.1.1-cache-keying',
      '0.1.2-cache-mtime_invalidation',
      '9.1.1-render-exceptions-template_line',
      '9.1.2-render-exceptions-empty_template',
      '1.0.1-render-php_code-verbatim',
      '1.1.2-render-outputs-escape',
      '1.1.3-render-outputs-quote_aware',
      '1.2.1-render-scope-isolation',
      '4.1.1-render-inheritance-extends',
      '4.1.2-render-inheritance-nested',
      '4.1.3-render-inheritance-yield_default',
      '4.1.4-render-inheritance-cycle',
      '4.2.1-render-layout-default',
      '5.1.1-render-includes',
      '5.1.2-render-includes-with_data',
      '5.2.1-render-components-slots',
      '5.2.2-render-components-default_slot',
      '9.1.3-render-exceptions-nested_line',
      '9.1.4-render-exceptions-engine_location',
      '1.1.4-render-outputs-translate',
   ]
);
