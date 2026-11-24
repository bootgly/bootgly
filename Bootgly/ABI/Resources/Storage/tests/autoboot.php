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

      // @ Appended to preserve every existing selected-case index.
      '1.7-local-symlink-copy',
      '1.8-local-symlink-mkdir',
      '1.9-local-nul-path',
      '1.10-local-concurrent-mkdir',
      '6.2-s3-signature-pin',
      '7.1-s3-fake-objects',
      '7.2-s3-fake-multipart-write',
      '7.3-s3-fake-ranged-read',
      '7.4-s3-fake-presign',
      '7.5-s3-fake-uploads',
      '7.6-s3-fake-uploads-refusals',
      '8.1-s3-signer-vectors',
      '8.2-s3-signer-refusals',
      '8.3-s3-offline-handles',
      '7.7-s3-fake-list-pages',
      '8.4-s3-endpoint-port',
      '8.5-storage-trace-options',
      '7.8-s3-fake-presign-headers',
      '8.6-s3-signer-headers',
      // # 1.4.0: the credentials stay out of debug dumps and stack traces
      '8.7-storage-dump-redaction',
      '8.8-s3-send-trace',
      '8.9-storage-frames-trace',
      // # 1.4.0: a part sent by the server, and the failure of each call
      '7.9-s3-fake-uploads-put',
      '7.10-s3-fake-failures',
      '7.11-s3-fake-failures-fibers',
      '8.10-s3-writer-offline',
      '8.11-s3-reader-answers',
      '8.12-s3-failure-redaction',
      '7.12-storage-events-preserve',
      '8.13-s3-answer-head-offline',
      // # 1.4.0: the ETag a lost completion is judged by
      '8.14-s3-reader-fold',
   ]
);
