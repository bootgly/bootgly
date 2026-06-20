<?php

namespace Bootgly\ABI\Resources\Storage;

use Bootgly\ACI\Tests\Suite;

return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   // * Data
   suiteName: __NAMESPACE__,
   tests: [
      '1.1-local-write-read',
      '1.2-local-list',
      '1.3-local-copy-move',
      '1.4-local-make-clear',
      '1.5-local-jail',
      '1.6-local-symlink-jail',

      '2.1-memory-driver',

      '3.1-facade-disks',

      '4.1-events',

      '5.1-s3-write-read-delete',
      '5.2-s3-list-clear',
      '5.3-s3-copy-move-inspect',
      '5.4-s3-multipart',

      '6.1-s3-offline',
   ]
);
