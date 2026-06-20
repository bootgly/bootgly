<?php

use function ceil;
use function md5;
use function str_repeat;
use function substr;
use function uniqid;

use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(S3) E2E: a >16 MiB object round-trips via Multipart Upload + streamed read',
   skip: s3_skip(),
   test: function () {
      $Storage = s3_storage();
      $key = 'e2e-mp-' . uniqid() . '.bin';

      // ! 20 MiB → exceeds the 16 MiB part size, forcing a 2-part Multipart Upload
      $size = 20 * 1024 * 1024;
      $contents = substr(str_repeat('bootgly-stream-', (int) ceil($size / 15)), 0, $size);
      $checksum = md5($contents);

      yield assert(
         assertion: $Storage->write($key, source($contents)) === true,
         description: 'write() uploads a >16 MiB object via Multipart Upload'
      );
      yield assert(
         assertion: $Storage->measure($key) === $size,
         description: 'measure() reports the full multipart object size'
      );
      yield assert(
         assertion: md5((string) grab($Storage, $key)) === $checksum,
         description: 'read() streams the multipart object back intact'
      );

      $Storage->delete($key);
   }
);
