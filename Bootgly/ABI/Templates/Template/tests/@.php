<?php

namespace Bootgly\ABI\Templates\Template;

return [
   // * Config
   'autoBoot' => __DIR__,
   'autoInstance' => true,
   'autoResult' => true,
   'autoSummarize' => true,
   'exitOnFailure' => true,
   // * Data
   'suiteName' => __NAMESPACE__,
   'tests' => [
      '1.1.1-render-outputs-1',
      '2.1.1-render-loops-for',
      '3.1.1-render-conditionals-singly-ifs',
   ]
];
