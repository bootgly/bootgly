<?php

namespace Bootgly\ABI\__String;


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
      '1.x-dynamic-length',
      '1.x-dynamic-lowercase',
      '1.x-dynamic-pascalcase',
      '1.x-dynamic-uppercase',
   ]
];
