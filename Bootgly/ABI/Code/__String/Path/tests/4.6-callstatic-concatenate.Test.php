<?php


use Bootgly\ABI\Code\__String\Path;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should concatenate paths',
   test: function () {
      $path = Path::concatenate(['home', 'bootgly', 'bootgly', 'index.php'], offset: 2);
      yield assert(
         assertion: $path === 'bootgly/index.php',
         description: 'Path: ' . $path
      );
   }
);
