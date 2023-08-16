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
      '1.x-dynamic-props-length',
      '1.x-dynamic-props-lowercase',
      '1.x-dynamic-props-pascalcase',
      '2.x-dynamic-methods-pad',
      '2.x-dynamic-methods-search',
   ]
];
