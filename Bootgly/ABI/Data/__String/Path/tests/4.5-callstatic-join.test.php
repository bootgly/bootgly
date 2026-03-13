<?php


use Bootgly\ABI\Data\__String\Path;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should join path nodes',
   test: function () {
      $path = Path::join(['home', 'bootgly']);
      yield assert(
         assertion: $path === 'home/bootgly',
         description: 'Path: ' . $path
      );

      $path = Path::join(['home', 'bootgly'], absolute: true);
      yield assert(
         assertion: $path === '/home/bootgly',
         description: 'Path: ' . $path
      );

      $path = Path::join(['home', 'bootgly'], absolute: true, dir: true);
      yield assert(
         assertion: $path === '/home/bootgly/',
         description: 'Path: ' . $path
      );
   }
);
