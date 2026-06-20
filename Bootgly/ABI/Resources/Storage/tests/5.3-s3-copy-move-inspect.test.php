<?php

use function is_array;
use function uniqid;

use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(S3) E2E: copy/move/measure/inspect against a live endpoint',
   skip: s3_skip(),
   test: function () {
      $Storage = s3_storage();
      $base = 'e2e-cm-' . uniqid();

      $Storage->write("{$base}/src.txt", source('12345'));

      yield assert(
         assertion: $Storage->copy("{$base}/src.txt", "{$base}/copy.txt") === true
            && grab($Storage, "{$base}/copy.txt") === '12345',
         description: 'copy() duplicates the object'
      );
      yield assert(
         assertion: $Storage->measure("{$base}/src.txt") === 5,
         description: 'measure() returns the object size'
      );

      $info = $Storage->inspect("{$base}/src.txt");
      yield assert(
         assertion: is_array($info) && $info['size'] === 5 && $info['modified'] > 0,
         description: 'inspect() returns size and a modified timestamp'
      );

      yield assert(
         assertion: $Storage->move("{$base}/copy.txt", "{$base}/moved.txt") === true
            && $Storage->check("{$base}/copy.txt") === false
            && grab($Storage, "{$base}/moved.txt") === '12345',
         description: 'move() relocates the object and removes the source'
      );
      yield assert(
         assertion: $Storage->measure("{$base}/missing.txt") === false
            && $Storage->copy("{$base}/missing.txt", "{$base}/x.txt") === false,
         description: 'measure()/copy() report a missing source'
      );

      $Storage->clear($base);
   }
);
