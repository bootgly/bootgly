<?php

namespace Bootgly;

use Bootgly\API\Project;
use Bootgly\API\Projects;

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
      $Project = Projects::select(project: 'Bootgly');
      yield assert(
         assertion: $Project->path === Projects::CONSUMER_DIR . 'Bootgly/',
         description: 'Failed to select Project by name'
      );
   }
];
