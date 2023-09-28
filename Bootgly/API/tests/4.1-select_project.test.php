<?php

namespace Bootgly;

use Bootgly\API\Project;

return [
   // @ Configure
   'describe' => 'Select project',
   // @ Simulate
   // ...
   // @ Test
   'test' => function () {
      // ! Project 1 instance
      $Project1 = new Project;

      // @ Construct new Project Path
      $Project1->construct('Bootgly/');
      $Project1->name('Bootgly');

      // ---------

      // ! Project 2 instance
      $Project2 = new Project;

      // @ Select Project
      $path2 = $Project2->select(project: 'Bootgly');
      yield assert(
         assertion: $path2 === Project::CONSUMER_DIR . 'Bootgly/',
         description: 'Failed to select Project by name'
      );
   }
];
