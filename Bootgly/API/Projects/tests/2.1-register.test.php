<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Projects;


return new Specification(
   description: 'Projects::register() re-emits the registry allow-list deterministically',
   test: function () {
      // ! Scratch registry file
      $file = sys_get_temp_dir() . '/bootgly-test-registry-' . getmypid() . '.php';
      @unlink($file);

      // @ Register into a fresh file
      yield assert(
         assertion: Projects::register('App/API', ['interfaces' => ['WPI']], $file) === true,
         description: 'registers a new path into a fresh registry file'
      );

      $registry = include $file;
      yield assert(
         assertion: ($registry['App/API']['interfaces'] ?? null) === ['WPI'],
         description: 'the emitted registry binds the path to its interfaces'
      );

      // @ Register a second entry with the default flag
      Projects::register('App/Console', ['interfaces' => ['CLI']], $file);
      Projects::register('Zeta/Web', ['interfaces' => ['WPI'], 'default' => true], $file);

      $registry = include $file;
      yield assert(
         assertion: array_keys($registry) === ['App/API', 'App/Console', 'Zeta/Web'],
         description: 'entries are kept sorted alphabetically by project path'
      );
      yield assert(
         assertion: ($registry['Zeta/Web']['default'] ?? null) === true,
         description: 'the default flag is persisted'
      );

      // @ Re-register updates the existing key
      Projects::register('App/API', ['interfaces' => ['CLI', 'WPI']], $file);
      $registry = include $file;
      yield assert(
         assertion: ($registry['App/API']['interfaces'] ?? null) === ['CLI', 'WPI'],
         description: 're-registering a path updates its entry in place'
      );

      // ! Rejections
      yield assert(
         assertion: Projects::register('../Escape', ['interfaces' => ['CLI']], $file) === false,
         description: 'unsafe paths are rejected'
      );
      yield assert(
         assertion: Projects::register('App/Empty', [], $file) === false,
         description: 'entries without interfaces are rejected'
      );

      @unlink($file);
   }
);
