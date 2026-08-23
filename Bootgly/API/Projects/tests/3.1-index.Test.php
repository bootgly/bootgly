<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;
use Bootgly\API\Projects\Project;


return new Test(
   description: 'Projects::index() names the slot the most recent add() filled',
   test: function () {
      // ! The selection state is process-wide — snapshot and restore it, and
      //   collect every verdict BEFORE yielding so a failing assertion cannot
      //   strand the restore.
      $Projects = new ReflectionProperty(Projects::class, 'projects');
      $Index = new ReflectionProperty(Projects::class, 'index');
      $Indexes = new ReflectionProperty(Projects::class, 'indexes');
      $snapshot = [$Projects->getValue(), $Index->getValue(), $Indexes->getValue()];

      try {
         $Projects->setValue(null, []);
         $Index->setValue(null, 0);
         $Indexes->setValue(null, []);

         $P1 = new Project(boot: static function (): void {}, exportable: false, name: 'One');
         $P2 = new Project(boot: static function (): void {}, exportable: false, name: 'Two');

         $verdicts = [];
         $verdicts['index() before any add() is refused'] = Projects::index('Early') === false;
         $verdicts['first add fills slot 0'] = Projects::add($P1) === 0;
         $verdicts['index() records the name'] = Projects::index('One') === true;
         $verdicts['select() by name resolves the added project'] = Projects::select('One') === $P1;
         $verdicts['select() with no argument resolves the current project'] = Projects::select() === $P1;

         $verdicts['second add fills slot 1'] = Projects::add($P2) === 1;
         $verdicts['a second name is indexed'] = Projects::index('Two') === true;
         $verdicts['the first name keeps resolving its own project'] = Projects::select('One') === $P1;
         $verdicts['the second name resolves the second project'] = Projects::select('Two') === $P2;

         $verdicts['re-indexing a name is refused'] = Projects::index('One') === false;
         $verdicts['an empty name is refused'] = Projects::index('') === false;
         $verdicts['count() reports the added projects'] = Projects::count() === 2;
      }
      finally {
         $Projects->setValue(null, $snapshot[0]);
         $Index->setValue(null, $snapshot[1]);
         $Indexes->setValue(null, $snapshot[2]);
      }

      // @
      foreach ($verdicts as $description => $verdict) {
         yield assert(
            assertion: $verdict === true,
            description: $description
         );
      }
   }
);
