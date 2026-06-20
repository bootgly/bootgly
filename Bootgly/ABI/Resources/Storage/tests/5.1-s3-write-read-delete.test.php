<?php

use function uniqid;

use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(S3) E2E: write/read/check/delete round-trip against a live endpoint',
   skip: s3_skip(),
   test: function () {
      $Storage = s3_storage();
      $key = 'e2e-' . uniqid() . '.txt';

      yield assert(
         assertion: $Storage->write($key, source('hello world')) === true,
         description: 'write() uploads the object'
      );
      yield assert(
         assertion: grab($Storage, $key) === 'hello world',
         description: 'read() round-trips the contents'
      );
      yield assert(
         assertion: $Storage->check($key) === true,
         description: 'check() sees the object'
      );
      yield assert(
         assertion: grab($Storage, 'missing-' . uniqid()) === false,
         description: 'read() of a missing key returns false'
      );
      yield assert(
         assertion: $Storage->check('missing-' . uniqid()) === false,
         description: 'check() of a missing key returns false'
      );
      yield assert(
         assertion: $Storage->delete($key) === true && $Storage->check($key) === false,
         description: 'delete() removes the object'
      );
      yield assert(
         assertion: $Storage->delete($key) === true,
         description: 'delete() is idempotent on a missing key'
      );
   }
);
