<?php

use function sort;
use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(Local): list() returns immediate files; recursive walks subdirectories',
   test: function () {
      $root = sys_get_temp_dir() . '/bootgly-storage-' . uniqid();
      $Storage = new Storage(['disks' => ['local' => ['driver' => 'local', 'root' => $root]]]);

      $Storage->write('a.txt', source('1'));
      $Storage->write('b.txt', source('2'));
      $Storage->write('sub/c.txt', source('3'));

      $top = $Storage->list();
      sort($top);
      yield assert(
         assertion: $top === ['a.txt', 'b.txt'],
         description: 'list() returns only immediate files'
      );

      $all = $Storage->list('', true);
      sort($all);
      yield assert(
         assertion: $all === ['a.txt', 'b.txt', 'sub/c.txt'],
         description: 'recursive list() includes nested files as relative paths'
      );

      yield assert(
         assertion: $Storage->list('sub') === ['sub/c.txt'],
         description: 'list() of a subdirectory returns its files'
      );
      yield assert(
         assertion: $Storage->list('missing') === [],
         description: 'list() of a missing directory returns an empty array'
      );

      $Storage->clear();
   }
);
