<?php

use Bootgly\ABI\__String\Bytes;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      // bytes
      $bytes = Bytes::format(512, 2);

      assert(
         assertion: $bytes === '512 B',
         description: 'Bytes format - bytes: ' . $bytes
      );
      // kilobytes
      $kilobytes = Bytes::format(1024, 2);

      assert(
         assertion: $kilobytes === '1 KB',
         description: 'Bytes format - kilobytes: ' . $kilobytes
      );
      // megabytes
      $megabytes = Bytes::format(1024 * 1024, 2);

      assert(
         assertion: $megabytes === '1 MB',
         description: 'Bytes format - megabytes: ' . $megabytes
      );
      // gigabytes
      $gigabytes = Bytes::format(1024 * 1024 * 1024, 2);

      assert(
         assertion: $gigabytes === '1 GB',
         description: 'Bytes format - gigabytes: ' . $gigabytes
      );
      // terabytes
      $terabytes = Bytes::format(1024 * 1024 * 1024 * 1024, 2);

      assert(
         assertion: $terabytes === '1 TB',
         description: 'Bytes format - terabytes: ' . $terabytes
      );
      // @ Invalid
      // ...

      return true;
   }
];
