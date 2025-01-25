<?php

namespace Bootgly;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\API\Project;
use Bootgly\API\Projects;

return [
   // @ Configure
   'describe' => 'Select project',
   // @ Simulate
   // ...
   // @ Test
   'test' => new Assertions(function () {
      // ! Project 1 instance
      $Project1 = new Project;

      // @ Construct new Project Path
      $Project1->construct('Bootgly/');
      $Project1->name('Bootgly');

      // @ Add Project to list
      Projects::add($Project1);
      // @ Index Project
      Projects::index('Bootgly');

      // ---------

      // ! Project 2 instance
      new Project;

      // @ Select Project
      $Project = Projects::select(project: 'Bootgly');
      yield new Assertion(
         fallback: 'Failed to select Project by name'
      )
         ->expect($Project->path)
         ->to->be(Projects::CONSUMER_DIR . 'Bootgly/')
         ->assert();
   })
];
