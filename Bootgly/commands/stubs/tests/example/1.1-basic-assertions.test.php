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

// Basic API — the test is a Generator that `yield`s one native `assert()`
// per check: `assertion:` takes any boolean expression and `description:`
// names the check in the report.
//
// Docs: https://docs.bootgly.com/testing/basic/running-tests/overview/
return new Specification(
   description: 'Basic API: one yielded assert() per check',
   test: function () {
      $greeting = 'Hello, Bootgly!';

      yield assert(
         assertion: $greeting === 'Hello, Bootgly!',
         description: 'identical strings'
      );

      yield assert(
         assertion: str_contains($greeting, 'Bootgly'),
         description: 'any boolean expression works'
      );
   }
);
