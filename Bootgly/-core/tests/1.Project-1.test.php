<?php
namespace Bootgly;

return [
   // @ Configure
   'separators' => [
      'suite' => 'Project class Test'
   ],
   'describe' => 'Test methods construct, name, save, set and select',
   // @ Simulate
   // ...
   // @ Test
   'test' => function () {
      $expected1 = Project::PROJECTS_DIR . 'Bootgly/';
      $expected2 = Project::PROJECTS_DIR . 'Bootgly/CLI/';

      $Project1 = new Project;
      // @ Construct
      $path = $Project1->construct('Bootgly');
      assert($path === $expected1, 'Failed to construct Project path');
      // @ Name
      $Project1->name('Calopsita');
      // @ Save
      $path = $Project1->save();
      assert($path === $expected1, 'Failed to save Project path');
      // @ Get
      $path = $Project1->get(path: 0);
      assert($path === $expected1, 'Failed to get Project path');
      // ---
      // @ Construct
      $Project1->construct('Bootgly/CLI');
      // @ Get
      $path = $Project1->get(path: 1);
      assert($path === $expected2, 'Failed to get Project path 2');

      // @ Select
      $Project2 = new Project;
      $path2 = $Project2->select(project: 0);
      assert($path2 !== $expected1, 'Failed to select Project path 2');

      return true;
   }
];
