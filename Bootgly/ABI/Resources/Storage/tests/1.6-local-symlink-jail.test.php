<?php

use const DIRECTORY_SEPARATOR;
use function file_put_contents;
use function is_file;
use function mkdir;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(Local): a symlink inside the root cannot read/write/list/clear outside it',
   skip: DIRECTORY_SEPARATOR === '\\',   // symlinks are unreliable on Windows
   test: function () {
      $root = sys_get_temp_dir() . '/bootgly-storage-' . uniqid();
      $outside = sys_get_temp_dir() . '/bootgly-outside-' . uniqid();
      mkdir($root, 0775, true);
      mkdir($outside, 0775, true);
      file_put_contents($outside . '/secret.txt', 'TOPSECRET');
      symlink($outside, $root . '/link');   // escaping symlink planted inside the root

      $Storage = new Storage(['disks' => ['local' => ['driver' => 'local', 'root' => $root]]]);

      yield assert(
         assertion: grab($Storage, 'link/secret.txt') === false,
         description: 'read() through an escaping symlink is blocked'
      );
      yield assert(
         assertion: $Storage->open('local')->error !== '',
         description: 'a blocked read records the driver error'
      );

      $Storage->write('link/pwned.txt', source('x'));
      yield assert(
         assertion: is_file($outside . '/pwned.txt') === false,
         description: 'write() through an escaping symlink writes nothing outside the root'
      );
      yield assert(
         assertion: $Storage->list('link') === [],
         description: 'list() through an escaping symlink returns nothing'
      );

      $Storage->clear('link');
      yield assert(
         assertion: is_file($outside . '/secret.txt') === true,
         description: 'clear() through an escaping symlink leaves outside files intact'
      );

      // ! Cleanup
      unlink($root . '/link');
      $Storage->clear();
      unlink($outside . '/secret.txt');
      @rmdir($outside);
   }
);
