<?php

use Bootgly\ABI\Code\__String\Path;

use Bootgly\ACI\Tests\Assertion\Expectations\Matchers\VariadicDirPath;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should compare using the matcher "VariadicDirPath"',
   test: new Assertions(Case: function (): Generator
   {
      // ! Own fixture — versioned directories the test creates. System paths
      //   are distro-specific (/etc/php/<version>/ only exists on Debian).
      $base = sys_get_temp_dir() . '/bootgly-tests-variadic-' . getmypid() . '/';
      mkdir($base . '8.3', recursive: true);
      mkdir($base . '8.4', recursive: true);

      // Path
      $Path = new Path($base);
      $Path->match(path: '%', pattern: '8.*');
      yield new Assertion(
         description: 'Valid relative path',
      )
         ->assert(
            actual: (string) $Path,
            expected: new VariadicDirPath($base . '8.*'),
         );

      rmdir($base . '8.3');
      rmdir($base . '8.4');
      rmdir($base);
   })
);
