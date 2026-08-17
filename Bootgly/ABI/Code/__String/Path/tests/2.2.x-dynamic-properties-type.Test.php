<?php


use Bootgly\ABI\Code\__String\Path;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should return path type',
   test: function () {
      // ! Own fixture — a real directory the test creates. System paths are
      //   distro-specific (Debian ships /etc/php/<version>/, Fedora does not).
      $dir = sys_get_temp_dir() . '/bootgly-path-type-' . getmypid() . '/';
      mkdir($dir, recursive: true);

      // @
      // Valid
      $Path = new Path;
      $Path->real = true;
      $Path->construct($dir);

      yield assert(
         assertion: $Path->type === 'dir',
         description: 'Return Path type: ' . $Path->type
      );

      rmdir($dir);
   }
);
