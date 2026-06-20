<?php

use function is_array;
use function is_int;
use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(Local): copy/move/measure/inspect behave and report missing paths',
   test: function () {
      $root = sys_get_temp_dir() . '/bootgly-storage-' . uniqid();
      $Storage = new Storage(['disks' => ['local' => ['driver' => 'local', 'root' => $root]]]);

      $Storage->write('src.txt', source('12345'));

      yield assert(
         assertion: $Storage->copy('src.txt', 'copy.txt') === true
            && grab($Storage, 'copy.txt') === '12345',
         description: 'copy() duplicates the file contents'
      );
      yield assert(
         assertion: $Storage->measure('src.txt') === 5,
         description: 'measure() returns the byte length'
      );
      $info = $Storage->inspect('src.txt');
      yield assert(
         assertion: is_array($info) === true
            && $info['size'] === 5
            && is_int($info['modified']) === true,
         description: 'inspect() returns size and a Unix mtime'
      );
      yield assert(
         assertion: $Storage->move('copy.txt', 'moved.txt') === true
            && grab($Storage, 'moved.txt') === '12345'
            && $Storage->check('copy.txt') === false,
         description: 'move() relocates the file and removes the source'
      );
      yield assert(
         assertion: $Storage->measure('missing.txt') === false,
         description: 'measure() of a missing path returns false'
      );
      yield assert(
         assertion: $Storage->inspect('missing.txt') === false,
         description: 'inspect() of a missing path returns false'
      );
      yield assert(
         assertion: $Storage->copy('missing.txt', 'x.txt') === false,
         description: 'copy() of a missing source returns false'
      );

      $Storage->clear();
   }
);
