<?php

use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(Local): write/read/check/delete round-trip; missing read is false',
   test: function () {
      $root = sys_get_temp_dir() . '/bootgly-storage-' . uniqid();
      $Storage = new Storage([
         'disks' => [
            'local' => [
               'driver' => 'local',
               'root' => $root
            ]
         ]
      ]);

      yield assert(
         assertion: $Storage->write('hello.txt', source('world')) === true,
         description: 'write() returns true'
      );
      yield assert(
         assertion: grab($Storage, 'hello.txt') === 'world',
         description: 'read() streams out the stored contents'
      );
      yield assert(
         assertion: $Storage->check('hello.txt') === true,
         description: 'check() is true for an existing file'
      );
      yield assert(
         assertion: grab($Storage, 'missing.txt') === false,
         description: 'read() of a missing path returns false'
      );
      yield assert(
         assertion: $Storage->check('missing.txt') === false,
         description: 'check() of a missing path is false'
      );
      yield assert(
         assertion: $Storage->write('a/b/c/deep.txt', source('deep')) === true
            && grab($Storage, 'a/b/c/deep.txt') === 'deep',
         description: 'write() creates parent directories'
      );

      $deleted = $Storage->delete('hello.txt');
      yield assert(
         assertion: $deleted === true && $Storage->check('hello.txt') === false,
         description: 'delete() removes the file'
      );
      yield assert(
         assertion: $Storage->delete('never.txt') === true,
         description: 'delete() of a missing path is a no-op success'
      );

      $Storage->clear();
   }
);
