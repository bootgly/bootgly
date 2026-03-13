<?php


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should relativize paths',
   test: function () {
      $relative = Path::relativize('/foo/bar/tests/test2.php', '/foo/bar/');
      yield assert(
         assertion: $relative === 'tests/test2.php',
         description: 'Relative path: ' . $relative
      );
   }
);
