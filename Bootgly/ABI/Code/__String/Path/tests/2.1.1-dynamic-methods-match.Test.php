<?php


use Bootgly\ABI\Code\__String\Path;
use Bootgly\ACI\Tests\Assertion\Expectations\Matchers\VariadicDirPath;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should match paths',
   test: new Assertions(Case: function (): Generator
   {
      // ! Own fixture — versioned directories the test creates. System paths
      //   are distro-specific (/etc/php/<version>/ only exists on Debian).
      $base = sys_get_temp_dir() . '/bootgly-path-match-' . getmypid() . '/';
      mkdir($base . '8.3', recursive: true);
      mkdir($base . '8.4', recursive: true);

      // @
      // assertion: (string) $Path === "{$base}8.3" || (string) $Path === "{$base}8.4"
      $Path = new Path;
      $Path->match(path: $base . '%', pattern: '8.*');
      yield new Assertion(
         description: 'Valid absolute path',
         fallback: 'PHP path #1 (absolute) not matched!'
      )
         ->assert(
            actual: (string) $Path,
            expected: $base . '8.*',
            using: new VariadicDirPath
         );

      $Path = new Path($base);
      $Path->match(path: '%', pattern: '8.*');
      yield new Assertion(
         description: 'Valid relative path',
         fallback: 'PHP path #2 (relative) not matched!'
      )
         ->assert(
            actual: (string) $Path,
            expected: $base . '8.*',
            using: new VariadicDirPath
         );

      rmdir($base . '8.3');
      rmdir($base . '8.4');
      rmdir($base);
   })
);
