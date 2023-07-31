<?php
namespace Bootgly;

use Bootgly\API\Project;

return [
   // @ Configure
   'describe' => 'Test methods: construct, name, save, get and select',
   // @ Simulate
   // ...
   // @ Test
   'test' => function () {
      $expected1 = Project::CONSUMER_DIR . 'Bootgly/';
      $expected2 = Project::CONSUMER_DIR . 'Bootgly/CLI/';

      // ! Project 1 instance
      $Project1 = new Project;

      // @ Construct new Project Path
      $path1 = $Project1->construct('Bootgly/');
      assert($path1 === $expected1, 'Failed to construct Project path 1');
      // @ Name current Project
      $Project1->name('Bootgly');
      // @ Save last Project Path
      $path1 = $Project1->save();
      assert($path1 === $expected1, 'Failed to save Project path 1');

      // @ Get Project Path by Project Index
      $path1 = $Project1->get(index: 0);
      assert($path1 === $expected1, 'Failed to get Project path 1');

      // ---------

      // @ Construct new Project Path
      $Project1->construct('Bootgly/CLI');

      // @ Get Project Path by Project Index
      $path2 = $Project1->get(index: 1);
      assert($path2 === $expected2, 'Failed to get Project path 2');

      // ! Project 2 instance
      $Project2 = new Project;

      // @ Select first Project
      $path1 = $Project2->select(project: 0);
      assert($path1 === $expected1, 'Failed to select Project path 1 by index');

      // @ Construct
      $Project2->construct('Bootgly/CLI');
      // @ Name current Project
      $Project2->name('BootglyCLI');
      // @ Save last Project Path to static variable
      $path2 = $Project2->save();
      assert($path2 === $expected2, 'Failed to save Project path 2');

      // @ Select Project
      $path2 = $Project2->select(project: 'BootglyCLI');
      assert($path2 === $expected2, 'Failed to select Project path 2 by name');

      return true;
   }
];
