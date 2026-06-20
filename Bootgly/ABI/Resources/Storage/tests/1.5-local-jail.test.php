<?php

use function dirname;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(Local): path traversal is normalized and clamped inside the disk root',
   test: function () {
      $root = sys_get_temp_dir() . '/bootgly-storage-' . uniqid();
      $Storage = new Storage(['disks' => ['local' => ['driver' => 'local', 'root' => $root]]]);

      $Storage->write('../escape.txt', source('pwned'));

      yield assert(
         assertion: is_file($root . '/escape.txt') === true,
         description: 'the clamped file is created inside the root'
      );
      yield assert(
         assertion: is_file(dirname($root) . '/escape.txt') === false,
         description: 'nothing is written above the root'
      );
      yield assert(
         assertion: grab($Storage, '../escape.txt') === 'pwned',
         description: 'read() normalizes the same traversal to the clamped path'
      );

      $Storage->clear();
   }
);
