<?php

namespace Bootgly\ABI\IO\FS\Dir;

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
      '1.1-construct-real_dir',
      '1.2-construct-fake_dir',
      '2.x-dynamic-methods-scan',
      '3.x-dynamic-properties-writable',
   ]
];
