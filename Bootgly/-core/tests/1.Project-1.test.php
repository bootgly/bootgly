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

      // ! Project 1 instance
      $Project1 = new Project;
      // @ Construct
      $path1 = $Project1->construct('Bootgly');
      assert($path1 === $expected1, 'Failed to construct Project path 1');
      // @ Name
      $Project1->name('Bootgly');
      // @ Save
      $path1 = $Project1->save();
      assert($path1 === $expected1, 'Failed to save Project path 1');
      // @ Get
      $path1 = $Project1->get(path: 0);
      assert($path1 === $expected1, 'Failed to get Project path 1');
      // ---
      // @ Construct
      $Project1->construct('Bootgly/CLI');
      // @ Get
      $path2 = $Project1->get(path: 1);
      assert($path2 === $expected2, 'Failed to get Project path 2');

      // ! Project 2 instance
      $Project2 = new Project;
      // @ Select
      $path1 = $Project2->select(project: 0);
      assert($path1 === $expected1, 'Failed to select Project path 1 by index');
      // @ Construct
      $Project2->construct('Bootgly/CLI');
      // @ Name
      $Project2->name('BootglyCLI');
      // @ Save
      $path2 = $Project2->save();
      assert($path2 === $expected2, 'Failed to save Project path 2');
      // @ Select
      $path2 = $Project2->select(project: 'BootglyCLI');
      assert($path2 === $expected2, 'Failed to select Project path 2 by name');

      return true;
   }
];
