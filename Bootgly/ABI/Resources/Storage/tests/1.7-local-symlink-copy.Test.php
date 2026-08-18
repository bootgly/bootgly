<?php


use Bootgly\ABI\Resources\Storage;
use Bootgly\ACI\Tests\Suite\Test;

require_once __DIR__ . '/disk.php';


return new Test(
   description: 'Storage(Local): copy() replaces a symlinked target instead of writing through it',
   skip: DIRECTORY_SEPARATOR === '\\',   // symlinks are unreliable on Windows
   test: function () {
      $root = sys_get_temp_dir() . '/bootgly-storage-copy-' . uniqid();
      $outside = sys_get_temp_dir() . '/bootgly-outside-copy-' . uniqid();
      mkdir($root, 0775, true);
      mkdir($outside, 0775, true);

      $victim = $outside . '/victim.txt';
      file_put_contents($victim, 'ORIGINAL');

      $Storage = new Storage(['disks' => ['local' => ['driver' => 'local', 'root' => $root]]]);
      $Storage->write('payload.txt', source('PAYLOAD'));

      // @ An escaping file symlink planted inside the root: PHP's copy() follows it,
      //   so copy() must land on the link itself, not on what it points at (STORE-1)
      symlink($victim, $root . '/pointer.txt');

      $Storage->copy('payload.txt', 'pointer.txt');
      yield assert(
         assertion: file_get_contents($victim) === 'ORIGINAL',
         description: 'copy() through an escaping symlink writes nothing outside the root'
      );
      yield assert(
         assertion: is_link($root . '/pointer.txt') === false
            && grab($Storage, 'pointer.txt') === 'PAYLOAD',
         description: 'copy() replaced the symlink with the copied file, as write() and move() do'
      );

      // @ move() shares the threat model and must stay safe
      file_put_contents($victim, 'ORIGINAL');
      unlink($root . '/pointer.txt');
      symlink($victim, $root . '/pointer.txt');

      $Storage->write('payload2.txt', source('PAYLOAD2'));
      $Storage->move('payload2.txt', 'pointer.txt');
      yield assert(
         assertion: file_get_contents($victim) === 'ORIGINAL',
         description: 'move() through an escaping symlink writes nothing outside the root'
      );

      // @ Ordinary copies inside the root are untouched by the hardening
      $copied = $Storage->copy('payload.txt', 'sub/deep/copy.txt');
      yield assert(
         assertion: $copied === true && grab($Storage, 'sub/deep/copy.txt') === 'PAYLOAD',
         description: 'copy() into a new nested directory still works'
      );

      // @ No temp file is left behind by a successful copy
      yield assert(
         assertion: (glob($root . '/sub/deep/*.tmp') ?: []) === [],
         description: 'copy() leaves no temp file behind'
      );

      // ! Cleanup
      unlink($root . '/pointer.txt');
      $Storage->clear();
      unlink($victim);
      @rmdir($outside);
   }
);
