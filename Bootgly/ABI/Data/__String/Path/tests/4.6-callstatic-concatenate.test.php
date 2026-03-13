<?php


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should concatenate paths',
   test: function () {
      $path = Path::concatenate(['home', 'bootgly', 'bootgly', 'index.php'], offset: 2);
      yield assert(
         assertion: $path === 'bootgly/index.php',
         description: 'Path: ' . $path
      );
   }
);
