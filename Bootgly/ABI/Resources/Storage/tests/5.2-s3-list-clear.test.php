<?php

use function sort;
use function uniqid;

use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(S3) E2E: list (recursive + delimited) and clear against a live endpoint',
   skip: s3_skip(),
   test: function () {
      $Storage = s3_storage();
      $prefix = 'e2e-list-' . uniqid();

      $Storage->write("{$prefix}/a.txt", source('1'));
      $Storage->write("{$prefix}/b.txt", source('2'));
      $Storage->write("{$prefix}/sub/c.txt", source('3'));

      $recursive = $Storage->list($prefix, true);
      sort($recursive);
      yield assert(
         assertion: $recursive === ["{$prefix}/a.txt", "{$prefix}/b.txt", "{$prefix}/sub/c.txt"],
         description: 'recursive list() returns every key under the prefix'
      );

      $immediate = $Storage->list($prefix, false);
      sort($immediate);
      yield assert(
         assertion: $immediate === ["{$prefix}/a.txt", "{$prefix}/b.txt"],
         description: 'non-recursive list() excludes keys under subdirectories (delimiter)'
      );

      yield assert(
         assertion: $Storage->clear($prefix) === true && $Storage->list($prefix, true) === [],
         description: 'clear() removes every key under the prefix'
      );
   }
);
