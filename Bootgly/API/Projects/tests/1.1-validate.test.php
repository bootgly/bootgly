<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Projects;


return new Specification(
   description: 'Projects::validate() enforces path-safety and the registry allow-list',
   test: function () {
      // ! Registered projects are accepted
      yield assert(
         assertion: Projects::validate('Demo/HTTP_Server_CLI') === true,
         description: 'a registered nested path passes'
      );
      yield assert(
         assertion: Projects::validate('Benchmark/TCP_Server_CLI') === true,
         description: 'another registered nested path passes'
      );

      // ! Grouping containers are not registry keys → rejected
      yield assert(
         assertion: Projects::validate('Demo') === false,
         description: 'the Demo container is not a registry key'
      );
      yield assert(
         assertion: Projects::validate('Benchmark') === false,
         description: 'the Benchmark container is not a registry key'
      );
      yield assert(
         assertion: Projects::validate('Demo/Unregistered') === false,
         description: 'an unregistered nested path is rejected'
      );

      // ! Unsafe inputs are rejected before any allow-list lookup
      $unsafe = [
         'traversal prefix'       => '../etc/passwd',
         'traversal mid-path'     => 'Demo/../../etc',
         'absolute path'          => '/etc/passwd',
         'backslash separator'    => 'Demo\\HTTP_Server_CLI',
         'null byte'              => "Demo/HTTP_Server_CLI\0",
         'double slash'           => 'Demo//HTTP_Server_CLI',
         'trailing slash'         => 'Demo/HTTP_Server_CLI/',
         'empty string'           => '',
      ];
      foreach ($unsafe as $label => $input) {
         yield assert(
            assertion: Projects::validate($input) === false,
            description: "unsafe input rejected: {$label}"
         );
      }
   }
);
