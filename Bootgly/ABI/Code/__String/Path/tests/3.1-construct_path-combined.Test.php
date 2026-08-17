<?php


use Bootgly\ABI\Code\__String\Path;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should construct combined',
   test: function () {
      $Path = new Path;
      // * Config
      // @ convert
      $Path->convert = true;
      $Path->lowercase = true;
      // @ fix
      $Path->fix = true;
      $Path->dir_ = true;
      $Path->normalize = true;
      // @ valid
      $Path->real = true;

      $Path2 = clone $Path;

      // ! Own fixture — `real` needs an existing directory, and system paths
      //   are distro-specific (/etc/php only exists on Debian). The fixture
      //   name is lowercase because `lowercase` runs before `real` resolves.
      $temp = sys_get_temp_dir();
      $name = 'bootgly-path-combined-' . getmypid();
      mkdir("$temp/$name/8.2", recursive: true);

      // @
      // Valid — mixed separators, uppercase and a `..` segment
      $Path->construct(str_replace('/', '\\', strtoupper($temp)) . "\\/$name\\8.2/..");
      yield assert(
         assertion: (string) $Path === "$temp/$name",
         description: 'Combined path is not valid!'
      );

      rmdir("$temp/$name/8.2");
      rmdir("$temp/$name");
      // Invalid
      $Path2->construct('/usr/bin/fakebootgly');
      yield assert(
         assertion: (string) $Path2 === '',
         description: 'Fake path valid?!'
      );
   }
);
