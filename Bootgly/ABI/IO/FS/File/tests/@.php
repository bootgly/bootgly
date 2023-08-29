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
      // ---
      '2.2.1-dynamic_get-constructed-properties-exists',
      '2.2.2-dynamic_get-constructed-properties-size',
      '2.2.3-dynamic_get-constructed-properties-lines',

      '2.2.4-dynamic_get-constructed-properties-permissions',
      '2.2.5-dynamic_get-constructed-properties-readable',
      '2.2.6-dynamic_get-constructed-properties-executable',
      '2.2.7-dynamic_get-constructed-properties-writable',
      '2.2.8-dynamic_get-constructed-properties-owner',
      '2.2.9-dynamic_get-constructed-properties-group',

      '2.2.c-dynamic_get-constructed-properties-MIME',
      '2.2.c-dynamic_get-constructed-properties-format',
      '2.2.c-dynamic_get-constructed-properties-subtype',

      '3.1.x-dynamic_set-constructed-properties-contents',
   ]
];
