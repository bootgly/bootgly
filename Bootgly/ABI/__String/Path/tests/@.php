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
   'tests' => [
      // construct
      '1.1-construct_path',
      '1.2-construct_path-lowercase',
      '1.3-construct_path-dir_',
      '1.4-construct_path-real',
      '2.1.1-dynamic-methods-match',
      '3.1-construct_path-combined',
      // Call static
      '4.1-callstatic-normalize',
      '4.2-callstatic-cut',
      '4.3-callstatic-split',
      '4.4-callstatic-relativize',
      '4.5-callstatic-join',
      '4.6-callstatic-concatenate',
   ]
];
