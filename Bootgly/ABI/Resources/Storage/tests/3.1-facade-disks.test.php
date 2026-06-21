<?php

use function sys_get_temp_dir;
use function uniqid;
use InvalidArgumentException;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ABI\Resources\Storage\Drivers\Memory;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(facade): disks resolve and cache; unknown disks throw; custom drivers register',
   test: function () {
      $rootA = sys_get_temp_dir() . '/bootgly-storage-' . uniqid();
      $rootB = sys_get_temp_dir() . '/bootgly-storage-' . uniqid();

      $Storage = new Storage([
         'default' => 'a',
         'disks' => [
            'a' => ['driver' => 'local', 'root' => $rootA],
            'b' => ['driver' => 'local', 'root' => $rootB],
         ],
      ]);

      yield assert(
         assertion: $Storage->open() === $Storage->open('a'),
         description: 'open() returns the configured default disk'
      );
      yield assert(
         assertion: $Storage->open('a') === $Storage->open('a'),
         description: 'open() caches one driver instance per disk name'
      );

      $Storage->open('a')->write('x.txt', source('A'));
      $Storage->open('b')->write('x.txt', source('B'));
      yield assert(
         assertion: grab($Storage->open('a'), 'x.txt') === 'A'
            && grab($Storage->open('b'), 'x.txt') === 'B',
         description: 'disks are isolated by their own root'
      );

      $threw = false;
      try {
         $Storage->open('ghost');
      } catch (InvalidArgumentException) {
         $threw = true;
      }
      yield assert(
         assertion: $threw === true,
         description: 'an unconfigured disk throws InvalidArgumentException'
      );

      // ! Register a custom driver type and wire a disk to it
      $Storage->Drivers->register('mem2', Memory::class);
      $Storage->Config->disks['scratch'] = ['driver' => 'mem2'];
      $Storage->open('scratch')->write('k', source('v'));
      yield assert(
         assertion: grab($Storage->open('scratch'), 'k') === 'v',
         description: 'a registered driver powers a custom disk'
      );

      $Storage->open('a')->clear();
      $Storage->open('b')->clear();
   }
);
