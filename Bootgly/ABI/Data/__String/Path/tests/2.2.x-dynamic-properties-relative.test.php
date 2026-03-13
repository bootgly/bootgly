<?php


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should return true if path is relative',
   test: function () {
      // @
      // Valid - relative
      $Path = new Path('www/bootgly/index.php');
      yield assert(
         assertion: $Path->relative === true,
         description: 'Path is relative!'
      );
      // Invalid - absolute
      $Path = new Path('/etc/php/');
      yield assert(
         assertion: $Path->relative === false,
         description: 'Path is absolute!'
      );
   }
);
