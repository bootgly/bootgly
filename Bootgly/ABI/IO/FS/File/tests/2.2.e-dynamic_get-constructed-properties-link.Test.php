<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: '',
   test: function () {
      // ! Own fixture — a symlink the test creates. System symlinks are
      //   distro-specific (/bin/sh points to dash on Debian, bash on Fedora).
      $target = sys_get_temp_dir() . '/bootgly-file-link-target-' . getmypid();
      $link = sys_get_temp_dir() . '/bootgly-file-link-' . getmypid();
      touch($target);
      symlink($target, $link);

      // @ Valid
      $File1 = new File($link);
      yield assert(
         assertion: $File1->link === $target,
         description: 'File #1 - should have link value!' . $File1->link
      );

      unlink($link);
      unlink($target);

      // @ Neutral
      $File2 = new File('');
      yield assert(
         assertion: $File2->link === null,
         description: 'File #2 - empty path - link should be null'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.Test.php');
      yield assert(
         assertion: $File3->link === null,
         description: 'File #3 - fake file - link should be null'
      );
   }
);
