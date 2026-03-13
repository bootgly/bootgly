<?php


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should return path type',
   test: function () {
      // @
      // Valid
      $Path = new Path;
      $Path->real = true;
      $Path->construct('/etc/php/8.3/');

      yield assert(
         assertion: $Path->type === 'dir',
         description: 'Return Path type: ' . $Path->type
      );
   }
);
