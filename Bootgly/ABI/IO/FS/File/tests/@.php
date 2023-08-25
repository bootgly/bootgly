<?php

namespace Bootgly\ABI\IO\FS\File;

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
      '1.1-construct-real_file',

      '2.1.x-dynamic_get-not_constructed-properties-basename',
      '2.1.x-dynamic_get-not_constructed-properties-name',
      '2.1.x-dynamic_get-not_constructed-properties-extension',
      '2.1.x-dynamic_get-not_constructed-properties-parent',
      '2.2.x-dynamic_get-constructed-properties-exists',
      '2.2.x-dynamic_get-constructed-properties-lines',

      '3.1.x-dynamic_set-constructed-properties-contents',
   ]
];
