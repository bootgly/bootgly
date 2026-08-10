<?php


use Bootgly\ABI\Code\__String\Path;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Suite\Test\Separator;


return new Test(
   Separator: new Separator(left: 'Static methods'),
   description: 'It should normalize path',
   test: function () {
      $normalized = Path::normalize('../../etc/passwd');

      yield assert(
         $normalized === 'etc/passwd',
         'Path not normalized!'
      );
   }
);
