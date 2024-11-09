<?php

namespace Bootgly\ADI\Table;

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
      '1.0-construct-set_data',
      '2.1-methods-operations-by_column',
      '2.2-methods-searchs-by_column',
   ]
];
