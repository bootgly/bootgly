<?php

use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(Local): make() creates directories; clear() empties a path or the whole disk',
   test: function () {
      $root = sys_get_temp_dir() . '/bootgly-storage-' . uniqid();
      $Storage = new Storage(['disks' => ['local' => ['driver' => 'local', 'root' => $root]]]);

      yield assert(
         assertion: $Storage->make('dir/sub') === true && $Storage->check('dir/sub') === true,
         description: 'make() creates a nested directory'
      );

      $Storage->write('dir/sub/f.txt', source('x'));
      $Storage->write('top.txt', source('y'));

      yield assert(
         assertion: $Storage->clear('dir') === true && $Storage->check('dir/sub/f.txt') === false,
         description: 'clear(path) empties the given subdirectory'
      );
      yield assert(
         assertion: $Storage->check('top.txt') === true,
         description: 'clear(path) leaves files outside the path untouched'
      );
      yield assert(
         assertion: $Storage->clear() === true && $Storage->list('', true) === [],
         description: 'clear() empties the whole disk'
      );
   }
);
