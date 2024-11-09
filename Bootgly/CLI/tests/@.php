<?php
return [
   // * Config
   'autoBoot' => __DIR__,
   'autoInstance' => true,
   'autoReport' => true,
   'autoSummarize' => true,
   'exitOnFailure' => true,
   // * Data
   'suiteName' => 'Command class test',
   'tests' => [
      '_Commands-register-01',
      '_Commands-register-02',
   ]
];
