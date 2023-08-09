<?php
return [
   // * Config
   'autoBoot' => __DIR__,
   'autoInstance' => true,
   'autoResult' => true,
   'autoSummarize' => true,
   'exitOnFailure' => true,
   // * Data
   'suiteName' => 'Path',
   'files' => [
      // construct
      '1.1-construct_path',
      '1.2-construct_path-lowercase',
      '1.3-construct_path-dir_',
      '1.4-construct_path-real',
      #'1.5-construct_path-match',
      '2.1-construct_path-combined',
      // Call static
      '3.1-callstatic-normalize',
   ]
];
