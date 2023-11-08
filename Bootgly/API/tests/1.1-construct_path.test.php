<?php
namespace Bootgly;

use Bootgly\API\Project;
use Bootgly\API\Projects;

return [
   // @ configure
   'describe' => 'Test dynamic method: construct',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // ! Project 1 instance
      $Project1 = new Project;

      $path1 = $Project1->construct('Bootgly/');
      yield assert(
         assertion: $path1 === Projects::CONSUMER_DIR . 'Bootgly/',
         description: 'Failed to construct Project path 1'
      );

      $path2 = $Project1->construct('Bootgly/CLI');
      yield assert(
         assertion: $path2 === Projects::CONSUMER_DIR . 'Bootgly/CLI/',
         description: 'Failed to construct Project path 2'
      );
   }
];
