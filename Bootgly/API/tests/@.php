<?php
return [
   // * Config
   'autoBoot' => __DIR__,
   'autoInstance' => true,
   'autoReport' => true,
   'autoSummarize' => true,
   'exitOnFailure' => true,
   // * Data
   'suiteName' => 'Project class Test',
   'tests' => [
      '1.1-construct_path',
      '2.1-name_path',
      '3.1-get_path',
      '4.1-select_project'
   ]
];
