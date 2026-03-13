<?php
namespace Bootgly;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Project;
use Bootgly\API\Projects;


return new Specification(
   description: 'Naming and selecting project by name',
   test: function () {
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
         fallback: 'Failed to select Project path by name'
      )
         ->assert(
            actual: $Project->path,
            expected: Projects::CONSUMER_DIR . 'Bootgly/CLI/',
         );
   }
);
