<?php
namespace Bootgly;

use Bootgly\ACI\Tests\Assertions\Assertion;

use Bootgly\API\Project;
use Bootgly\API\Projects;

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

      // @ Add Project to list
      Projects::add($Project1);
      // @ Index Project
      Projects::index('BootglyCLI');
      // @ Select Project by name
      $Project = Projects::select(project: 'BootglyCLI');

      yield new Assertion(
         assertion: $Project->path === Projects::CONSUMER_DIR . 'Bootgly/CLI/',
         fallback: 'Failed to select Project path by name'
      );
   }
];
