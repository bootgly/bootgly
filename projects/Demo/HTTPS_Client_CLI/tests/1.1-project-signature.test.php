<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Projects\Project;


// This is an example test — use it as a guide to write your own:
// each `N.N-name.test.php` file listed in `tests/autoboot.php` returns a
// Specification whose test generator `yield`s one `assert()` per check.
return new Specification(
   description: 'Project signature: metadata contract',
   test: function () {
      $Project = include __DIR__ . '/../HTTPS_Client_CLI.project.php';

      yield assert(
         assertion: $Project instanceof Project,
         description: 'the signature file returns a Project'
      );
      yield assert(
         assertion: $Project->name === 'Demo HTTPS Client CLI',
         description: 'name'
      );
      yield assert(
         assertion: $Project->description !== '',
         description: 'description'
      );
      yield assert(
         assertion: $Project->exportable === true,
         description: 'exportable — listed by the import picker'
      );
   }
);
