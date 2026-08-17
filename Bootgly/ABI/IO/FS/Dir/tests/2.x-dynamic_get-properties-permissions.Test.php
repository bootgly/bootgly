<?php

use Bootgly\ABI\IO\FS\Dir;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: '',
   test: function () {
      // @ Valid
      $Dir1 = new Dir(__DIR__);
      yield assert(
         assertion: $Dir1->permissions === 0755 || $Dir1->permissions === 0750,
         description: 'Current directory permissions (get): ' . $Dir1->permissions
      );

      // ! Own fixture — a directory with known permissions. System paths are
      //   distro-specific (/usr/sbin is a 0755 directory on Debian, but a
      //   symlink to /usr/bin on Fedora since UsrMerge).
      $path = sys_get_temp_dir() . '/bootgly-dir-permissions-' . getmypid();
      mkdir($path);
      chmod($path, 0755); // ! explicit: mkdir() modes are masked by the umask

      // @ Fixed
      $Dir2 = new Dir($path);
      yield assert(
         assertion: $Dir2->permissions === 0755,
         description: 'The fixture directory cannot have modified permissions!'
      );

      rmdir($path);
   }
);
