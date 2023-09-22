<?php

namespace Bootgly\ABI\Templates\Template;

use Bootgly\ABI\Templates\Template;

return [
   // * Config
   'autoBoot' => __DIR__,
   'autoInstance' => true,
   'autoResult' => true,
   'autoSummarize' => true,
   'exitOnFailure' => true,
   'testables' => [new Template('')],
   // * Data
   'suiteName' => __NAMESPACE__,
   'tests' => [
      '1.0.0-render-php_code',
      '1.1.1-render-outputs-1',
      '2.1.1-render-loops-for',
      '2.2.1-render-loops-while',
      '2.3.1.1-render-loops-foreach',
      '2.3.1.2-render-loops-foreach-break',
      '2.3.2-render-loops-foreach_with_metavar',
      '2.3.3-render-loops-nested_foreachs_with_metavar',
      '3.1.1-render-conditionals-singly_ifs',
      '3.1.2-render-conditionals-short_empty_ifs',
   ]
];
