<?php
namespace Bootgly;

use Bootgly\API\Project;

return [
   // @ Configure
   'describe' => 'Naming and selecting project by name',
   // @ Simulate
   // ...
   // @ Test
   'test' => function () {
      $Project1 = new Project;

      // @ Construct
      $Project1->construct('Bootgly/CLI');
      // @ Name current Project
      $Project1->name('BootglyCLI');
      // @ Select Project by name
      $path2 = $Project1->select(project: 'BootglyCLI');
      assert(
         assertion: $path2 === Project::CONSUMER_DIR . 'Bootgly/CLI/',
         description: 'Failed to select Project path by name'
      );

      return true;
   }
];
