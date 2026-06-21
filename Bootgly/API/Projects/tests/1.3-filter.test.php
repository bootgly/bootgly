<?php

use function in_array;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Projects;


return new Specification(
   description: 'Projects::filter() groups registered projects by interface',
   test: function () {
      $WPI = Projects::filter('WPI');
      $CLI = Projects::filter('CLI');

      yield assert(
         assertion: in_array('Demo/HTTP_Server_CLI', $WPI, true),
         description: 'WPI list contains the nested HTTP server'
      );
      yield assert(
         assertion: in_array('Demo/CLI', $WPI, true) === false,
         description: 'WPI list excludes a CLI-only project'
      );
      yield assert(
         assertion: in_array('Demo/CLI', $CLI, true),
         description: 'CLI list contains the registered console project'
      );
      yield assert(
         assertion: in_array('Benchmark/HTTP_Server_CLI', $WPI, true),
         description: 'WPI list contains the nested Benchmark server'
      );
      yield assert(
         assertion: $WPI[0] === 'Benchmark/HTTP_Server_CLI',
         description: 'registry (alphabetical) declaration order is preserved'
      );

      // ! The Web default is the flagged entry, not the first WPI in file order
      yield assert(
         assertion: Projects::pick('WPI') === 'Demo/HTTP_Server_CLI',
         description: 'pick() returns the flagged default, not the alphabetically first WPI'
      );
      yield assert(
         assertion: Projects::pick('CLI') === 'Demo/CLI',
         description: 'pick() falls back to the first registered when none is flagged'
      );
   }
);
