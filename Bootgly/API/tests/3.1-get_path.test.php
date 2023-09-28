<?php
namespace Bootgly;

use Bootgly\API\Project;

return [
   // @ Configure
   'describe' => 'Get project path by index',
   // @ Simulate
   // ...
   // @ Test
   'test' => function () {
      // ! Project 1 instance
      $Project1 = new Project;

      // @ Construct new Project Path
      $Project1->construct('Bootgly/');
      // @ Get Project Path by Project Index
      $path1 = $Project1->get(index: 0);
      yield assert(
         assertion: $path1 === Project::CONSUMER_DIR . 'Bootgly/',
         description: 'Failed to get Project path 1'
      );
   }
];
